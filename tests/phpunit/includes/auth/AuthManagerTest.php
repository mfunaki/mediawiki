<?php

namespace MediaWiki\Tests\Auth;

use DatabaseLogEntry;
use DomainException;
use DummySessionProvider;
use DynamicPropertyTestHelper;
use Exception;
use InvalidArgumentException;
use Language;
use LogicException;
use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AbstractPrimaryAuthenticationProvider;
use MediaWiki\Auth\AbstractSecondaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\ConfirmLinkSecondaryAuthenticationProvider;
use MediaWiki\Auth\CreatedAccountAuthenticationRequest;
use MediaWiki\Auth\CreateFromLoginAuthenticationRequest;
use MediaWiki\Auth\CreationReasonAuthenticationRequest;
use MediaWiki\Auth\Hook\AuthManagerLoginAuthenticateAuditHook;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Auth\Hook\SecuritySensitiveOperationStatusHook;
use MediaWiki\Auth\Hook\UserLoggedInHook;
use MediaWiki\Auth\PrimaryAuthenticationProvider;
use MediaWiki\Auth\RememberMeAuthenticationRequest;
use MediaWiki\Auth\UserDataAuthenticationRequest;
use MediaWiki\Auth\UsernameAuthenticationRequest;
use MediaWiki\Block\BlockManager;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\Block\SystemBlock;
use MediaWiki\Config\Config;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\StaticHookRegistry;
use MediaWiki\Languages\LanguageConverterFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\SessionInfo;
use MediaWiki\Session\SessionManager;
use MediaWiki\Session\UserInfo;
use MediaWiki\Status\Status;
use MediaWiki\Tests\Session\TestUtils;
use MediaWiki\User\BotPasswordStore;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserNameUtils;
use MediaWiki\Watchlist\WatchlistManager;
use MediaWikiIntegrationTestCase;
use ObjectCacheFactory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\Builder\InvocationMocker;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use StatusValue;
use TestLogger;
use TestUser;
use UnexpectedValueException;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectFactory\ObjectFactory;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\ReadOnlyMode;
use Wikimedia\ScopedCallback;
use Wikimedia\TestingAccessWrapper;

/**
 * @group AuthManager
 * @group Database
 * @covers \MediaWiki\Auth\AuthManager
 */
class AuthManagerTest extends MediaWikiIntegrationTestCase {
	/** @var WebRequest */
	protected $request;
	/** @var Config */
	protected $config;
	/** @var ObjectFactory */
	protected $objectFactory;
	/** @var ReadOnlyMode */
	protected $readOnlyMode;

	/** @var HookContainer */
	private $hookContainer;

	/** @var UserNameUtils */
	protected $userNameUtils;

	/** @var LoggerInterface */
	protected $logger;

	protected $preauthMocks = [];
	protected $primaryauthMocks = [];
	protected $secondaryauthMocks = [];

	/** @var AuthManager */
	protected $manager;
	/** @var TestingAccessWrapper */
	protected $managerPriv;

	/** @var BlockManager */
	private $blockManager;

	/** @var WatchlistManager */
	private $watchlistManager;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var Language */
	private $contentLanguage;

	/** @var LanguageConverterFactory */
	private $languageConverterFactory;

	/** @var BotPasswordStore */
	private $botPasswordStore;

	/** @var UserFactory */
	private $userFactory;

	/** @var UserIdentityLookup */
	private $userIdentityLookup;

	/** @var UserOptionsManager */
	private $userOptionsManager;

	/** @var ObjectCacheFactory */
	private $objectCacheFactory;

	/**
	 * Sets a mock on a hook
	 * @param string $hook
	 * @param string $hookInterface
	 * @param InvocationOrder $expect From $this->once(), $this->never(), etc.
	 * @return InvocationMocker $mock->expects( $expect )->method( ... ).
	 */
	protected function hook( $hook, $hookInterface, $expect ) {
		$mock = $this->getMockBuilder( $hookInterface )
			->onlyMethods( [ "on$hook" ] )
			->getMock();
		$this->hookContainer->register( $hook, $mock );
		return $mock->expects( $expect )->method( "on$hook" );
	}

	/**
	 * Unsets a hook
	 * @param string $hook
	 */
	protected function unhook( $hook ) {
		$this->hookContainer->clear( $hook );
	}

	/**
	 * Ensure a value is a clean Message object
	 *
	 * @param string|Message $key
	 * @param array $params
	 *
	 * @return Message
	 */
	protected function message( $key, $params = [] ) {
		if ( $key === null ) {
			return null;
		}
		if ( $key instanceof MessageSpecifier ) {
			$params = $key->getParams();
			$key = $key->getKey();
		}
		return new Message( $key, $params,
			MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( 'en' ) );
	}

	/**
	 * Test two AuthenticationResponses for equality.  We don't want to use regular assertEquals
	 * because that recursively compares members, which leads to false negatives if e.g. Language
	 * caches are reset.
	 *
	 * @param AuthenticationResponse $expected
	 * @param AuthenticationResponse $actual
	 * @param string $msg
	 */
	private function assertResponseEquals(
		AuthenticationResponse $expected, AuthenticationResponse $actual, $msg = ''
	) {
		foreach ( ( new ReflectionClass( $expected ) )->getProperties() as $prop ) {
			$name = $prop->getName();
			$usedMsg = ltrim( "$msg ($name)" );
			if ( $name === 'message' && $expected->message ) {
				$this->assertSame( $expected->message->__serialize(), $actual->message->__serialize(),
					$usedMsg );
			} else {
				$this->assertEquals( $expected->$name, $actual->$name, $usedMsg );
			}
		}
	}

	/**
	 * Initialize the AuthManagerConfig variable in $this->config
	 *
	 * Uses data from the various 'mocks' fields.
	 */
	protected function initializeConfig() {
		$config = [
			'preauth' => [
			],
			'primaryauth' => [
			],
			'secondaryauth' => [
			],
		];

		foreach ( [ 'preauth', 'primaryauth', 'secondaryauth' ] as $type ) {
			$key = $type . 'Mocks';
			foreach ( $this->$key as $mock ) {
				$config[$type][$mock->getUniqueId()] = [ 'factory' => static function () use ( $mock ) {
					return $mock;
				} ];
			}
		}

		$this->config->set( MainConfigNames::AuthManagerConfig, $config );
		$this->config->set( MainConfigNames::LanguageCode, 'en' );
		$this->config->set( MainConfigNames::NewUserLog, false );
		$this->config->set( MainConfigNames::RememberMe, RememberMeAuthenticationRequest::CHOOSE_REMEMBER );
	}

	/**
	 * Initialize $this->manager
	 * @param bool $regen Force a call to $this->initializeConfig()
	 */
	protected function initializeManager( $regen = false ) {
		// TODO clean this up, don't need to re fetch the services each time
		if ( $regen || !$this->config ) {
			$this->config = new HashConfig();
		}
		if ( $regen || !$this->request ) {
			$this->request = new FauxRequest();
		}
		if ( $regen || !$this->objectFactory ) {
			$services = $this->createNoOpAbstractMock( ContainerInterface::class );
			$this->objectFactory = new ObjectFactory( $services );
		}
		if ( $regen || !$this->readOnlyMode ) {
			$this->readOnlyMode = $this->getServiceContainer()->getReadOnlyMode();
		}
		if ( $regen || !$this->blockManager ) {
			// Override BlockManager::checkHost. Formerly testAuthorizeCreateAccount_DNSBlacklist
			// required *.localhost to resolve as 127.0.0.1, but that is system-dependent.
			$this->blockManager = new class(
				new ServiceOptions(
					BlockManager::CONSTRUCTOR_OPTIONS,
					$this->getServiceContainer()->getMainConfig()
				),
				$this->getServiceContainer()->getUserFactory(),
				$this->getServiceContainer()->getUserIdentityUtils(),
				LoggerFactory::getInstance( 'BlockManager' ),
				$this->getServiceContainer()->getHookContainer(),
				$this->getServiceContainer()->getDatabaseBlockStore(),
				$this->getServiceContainer()->getProxyLookup()
			) extends BlockManager
			{
				protected function checkHost( $hostname ) {
					return '127.0.0.1';
				}
			};
		}
		if ( $regen || !$this->watchlistManager ) {
			$this->watchlistManager = $this->getServiceContainer()->getWatchlistManager();
		}
		if ( $regen || !$this->hookContainer ) {
			// Set up a HookContainer we control
			$this->hookContainer = new HookContainer(
				new StaticHookRegistry( [], [], [] ),
				$this->objectFactory
			);
		}
		if ( $regen || !$this->userNameUtils ) {
			$this->userNameUtils = $this->getServiceContainer()->getUserNameUtils();
		}
		if ( $regen || !$this->loadBalancer ) {
			$this->loadBalancer = $this->getServiceContainer()->getDBLoadBalancer();
		}
		if ( $regen || !$this->contentLanguage ) {
			$this->contentLanguage = $this->getServiceContainer()->getContentLanguage();
		}
		if ( $regen || !$this->languageConverterFactory ) {
			$this->languageConverterFactory = $this->getServiceContainer()->getLanguageConverterFactory();
		}
		if ( $regen || !$this->botPasswordStore ) {
			$this->botPasswordStore = $this->getServiceContainer()->getBotPasswordStore();
		}
		if ( $regen || !$this->userFactory ) {
			$this->userFactory = $this->getServiceContainer()->getUserFactory();
		}
		if ( $regen || !$this->userIdentityLookup ) {
			$this->userIdentityLookup = $this->getServiceContainer()->getUserIdentityLookup();
		}
		if ( $regen || !$this->userOptionsManager ) {
			$this->userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		}
		if ( $regen || !$this->objectCacheFactory ) {
			$this->objectCacheFactory = $this->getServiceContainer()->getObjectCacheFactory();
		}
		if ( !$this->logger ) {
			$this->logger = new TestLogger();
		}

		if ( $regen || !$this->config->has( MainConfigNames::AuthManagerConfig ) ) {
			$this->initializeConfig();
		}
		$this->manager = new AuthManager(
			$this->request,
			$this->config,
			$this->objectFactory,
			$this->hookContainer,
			$this->readOnlyMode,
			$this->userNameUtils,
			$this->blockManager,
			$this->watchlistManager,
			$this->loadBalancer,
			$this->contentLanguage,
			$this->languageConverterFactory,
			$this->botPasswordStore,
			$this->userFactory,
			$this->userIdentityLookup,
			$this->userOptionsManager,
			$this->objectCacheFactory
		);
		$this->manager->setLogger( $this->logger );
		$this->managerPriv = TestingAccessWrapper::newFromObject( $this->manager );
	}

	/**
	 * Setup SessionManager with a mock session provider
	 * @param bool|null $canChangeUser If non-null, canChangeUser will be mocked to return this
	 * @param array $methods Additional methods to mock
	 * @return array (MediaWiki\Session\SessionProvider, ScopedCallback)
	 */
	protected function getMockSessionProvider( $canChangeUser = null, array $methods = [] ) {
		if ( !$this->config ) {
			$this->config = new HashConfig();
			$this->initializeConfig();
		}
		$this->config->set( MainConfigNames::ObjectCacheSessionExpiry, 100 );

		$methods[] = '__toString';
		$methods[] = 'describe';
		if ( $canChangeUser !== null ) {
			$methods[] = 'canChangeUser';
		}
		$provider = $this->getMockBuilder( DummySessionProvider::class )
			->onlyMethods( $methods )
			->getMock();
		$provider->method( '__toString' )
			->willReturn( 'MockSessionProvider' );
		$provider->method( 'describe' )
			->willReturn( 'MockSessionProvider sessions' );
		if ( $canChangeUser !== null ) {
			$provider->method( 'canChangeUser' )
				->willReturn( $canChangeUser );
		}
		$this->config->set( MainConfigNames::SessionProviders, [
			[ 'factory' => static function () use ( $provider ) {
				return $provider;
			} ],
		] );

		$manager = new SessionManager( [
			'config' => $this->config,
			'logger' => new NullLogger(),
			'store' => new HashBagOStuff(),
		] );
		TestingAccessWrapper::newFromObject( $manager )->getProvider( (string)$provider );

		$reset = TestUtils::setSessionManagerSingleton( $manager );

		if ( $this->request ) {
			$manager->getSessionForRequest( $this->request );
		}

		return [ $provider, $reset ];
	}

	public function testCanAuthenticateNow() {
		$this->initializeManager();

		[ $provider, $reset ] = $this->getMockSessionProvider( false );
		$this->assertFalse( $this->manager->canAuthenticateNow() );
		ScopedCallback::consume( $reset );

		[ $provider, $reset ] = $this->getMockSessionProvider( true );
		$this->assertTrue( $this->manager->canAuthenticateNow() );
		ScopedCallback::consume( $reset );
	}

	public function testNormalizeUsername() {
		$mocks = [
			$this->createMock( AbstractPrimaryAuthenticationProvider::class ),
			$this->createMock( AbstractPrimaryAuthenticationProvider::class ),
			$this->createMock( AbstractPrimaryAuthenticationProvider::class ),
			$this->createMock( AbstractPrimaryAuthenticationProvider::class ),
		];
		foreach ( $mocks as $key => $mock ) {
			$mock->method( 'getUniqueId' )->willReturn( $key );
		}
		$mocks[0]->expects( $this->once() )->method( 'providerNormalizeUsername' )
			->with( $this->identicalTo( 'XYZ' ) )
			->willReturn( 'Foo' );
		$mocks[1]->expects( $this->once() )->method( 'providerNormalizeUsername' )
			->with( $this->identicalTo( 'XYZ' ) )
			->willReturn( 'Foo' );
		$mocks[2]->expects( $this->once() )->method( 'providerNormalizeUsername' )
			->with( $this->identicalTo( 'XYZ' ) )
			->willReturn( null );
		$mocks[3]->expects( $this->once() )->method( 'providerNormalizeUsername' )
			->with( $this->identicalTo( 'XYZ' ) )
			->willReturn( 'Bar!' );

		$this->primaryauthMocks = $mocks;

		$this->initializeManager();

		$this->assertSame( [ 'Foo', 'Bar!' ], $this->manager->normalizeUsername( 'XYZ' ) );
	}

	/**
	 * @dataProvider provideSecuritySensitiveOperationStatus
	 * @param bool $mutableSession
	 */
	public function testSecuritySensitiveOperationStatus( $mutableSession ) {
		$this->logger = new NullLogger();
		$user = $this->getTestSysop()->getUser();
		$provideUser = null;
		$reauth = $mutableSession ? AuthManager::SEC_REAUTH : AuthManager::SEC_FAIL;

		[ $provider, $reset ] = $this->getMockSessionProvider(
			$mutableSession, [ 'provideSessionInfo' ]
		);
		$provider->method( 'provideSessionInfo' )
			->willReturnCallback( static function () use ( $provider, &$provideUser ) {
				return new SessionInfo( SessionInfo::MIN_PRIORITY, [
					'provider' => $provider,
					'id' => DummySessionProvider::ID,
					'persisted' => true,
					'userInfo' => UserInfo::newFromUser( $provideUser, true )
				] );
			} );
		$this->initializeManager();

		$this->config->set( MainConfigNames::ReauthenticateTime, [] );
		$this->config->set( MainConfigNames::AllowSecuritySensitiveOperationIfCannotReauthenticate, [] );
		$provideUser = new User;
		$session = $provider->getManager()->getSessionForRequest( $this->request );
		$this->assertSame( 0, $session->getUser()->getId() );

		// Anonymous user => reauth
		$session->set( 'AuthManager:lastAuthId', 0 );
		$session->set( 'AuthManager:lastAuthTimestamp', time() - 5 );
		$this->assertSame( $reauth, $this->manager->securitySensitiveOperationStatus( 'foo' ) );

		$provideUser = $user;
		$session = $provider->getManager()->getSessionForRequest( $this->request );
		$this->assertSame( $user->getId(), $session->getUser()->getId() );

		// Error for no default (only gets thrown for non-anonymous user)
		$session->set( 'AuthManager:lastAuthId', $user->getId() + 1 );
		$session->set( 'AuthManager:lastAuthTimestamp', time() - 5 );
		try {
			$this->manager->securitySensitiveOperationStatus( 'foo' );
			$this->fail( 'Expected exception not thrown' );
		} catch ( UnexpectedValueException $ex ) {
			$this->assertSame(
				$mutableSession
					? '$wgReauthenticateTime lacks a default'
					: '$wgAllowSecuritySensitiveOperationIfCannotReauthenticate lacks a default',
				$ex->getMessage()
			);
		}

		if ( $mutableSession ) {
			$this->config->set( MainConfigNames::ReauthenticateTime, [
				'test' => 100,
				'test2' => -1,
				'default' => 10,
			] );

			// Mismatched user ID
			$session->set( 'AuthManager:lastAuthId', $user->getId() + 1 );
			$session->set( 'AuthManager:lastAuthTimestamp', time() - 5 );
			$this->assertSame(
				AuthManager::SEC_REAUTH, $this->manager->securitySensitiveOperationStatus( 'foo' )
			);
			$this->assertSame(
				AuthManager::SEC_REAUTH, $this->manager->securitySensitiveOperationStatus( 'test' )
			);
			$this->assertSame(
				AuthManager::SEC_OK, $this->manager->securitySensitiveOperationStatus( 'test2' )
			);

			// Missing time
			$session->set( 'AuthManager:lastAuthId', $user->getId() );
			$session->set( 'AuthManager:lastAuthTimestamp', null );
			$this->assertSame(
				AuthManager::SEC_REAUTH, $this->manager->securitySensitiveOperationStatus( 'foo' )
			);
			$this->assertSame(
				AuthManager::SEC_REAUTH, $this->manager->securitySensitiveOperationStatus( 'test' )
			);
			$this->assertSame(
				AuthManager::SEC_OK, $this->manager->securitySensitiveOperationStatus( 'test2' )
			);

			// Recent enough to pass
			$session->set( 'AuthManager:lastAuthTimestamp', time() - 5 );
			$this->assertSame(
				AuthManager::SEC_OK, $this->manager->securitySensitiveOperationStatus( 'foo' )
			);

			// Not recent enough to pass
			$session->set( 'AuthManager:lastAuthTimestamp', time() - 20 );
			$this->assertSame(
				AuthManager::SEC_REAUTH, $this->manager->securitySensitiveOperationStatus( 'foo' )
			);
			// But recent enough for the 'test' operation
			$this->assertSame(
				AuthManager::SEC_OK, $this->manager->securitySensitiveOperationStatus( 'test' )
			);
		} else {
			$this->config->set( MainConfigNames::AllowSecuritySensitiveOperationIfCannotReauthenticate, [
				'test' => false,
				'default' => true,
			] );

			$this->assertEquals(
				AuthManager::SEC_OK, $this->manager->securitySensitiveOperationStatus( 'foo' )
			);

			$this->assertEquals(
				AuthManager::SEC_FAIL, $this->manager->securitySensitiveOperationStatus( 'test' )
			);
		}

		// Test hook, all three possible values
		foreach ( [
			AuthManager::SEC_OK => AuthManager::SEC_OK,
			AuthManager::SEC_REAUTH => $reauth,
			AuthManager::SEC_FAIL => AuthManager::SEC_FAIL,
		] as $hook => $expect ) {
			$this->hook( 'SecuritySensitiveOperationStatus',
				SecuritySensitiveOperationStatusHook::class,
				$this->exactly( 2 )
			)
				->with(
					/* $status */ $this->anything(),
					/* $operation */ $this->anything(),
					/* $session */ $this->callback( static function ( $s ) use ( $session ) {
						return $s->getId() === $session->getId();
					} ),
					/* $timeSinceAuth*/ $mutableSession
						? $this->equalToWithDelta( 500, 2 )
						: -1
				)
				->willReturnCallback( static function ( &$v ) use ( $hook ) {
					$v = $hook;
					return true;
				} );
			$session->set( 'AuthManager:lastAuthTimestamp', time() - 500 );
			$this->assertEquals(
				$expect, $this->manager->securitySensitiveOperationStatus( 'test' ), "hook $hook"
			);
			$this->assertEquals(
				$expect, $this->manager->securitySensitiveOperationStatus( 'test2' ), "hook $hook"
			);
			$this->unhook( 'SecuritySensitiveOperationStatus' );
		}

		ScopedCallback::consume( $reset );
	}

	public static function provideSecuritySensitiveOperationStatus() {
		return [
			[ true ],
			[ false ],
		];
	}

	/**
	 * @dataProvider provideUserCanAuthenticate
	 * @param bool $primary1Can
	 * @param bool $primary2Can
	 * @param bool $expect
	 */
	public function testUserCanAuthenticate( $primary1Can, $primary2Can, $expect ) {
		$userName = 'TestUserCanAuthenticate';
		$mock1 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock1->method( 'getUniqueId' )
			->willReturn( 'primary1' );
		$mock1->method( 'testUserCanAuthenticate' )
			->with( $userName )
			->willReturn( $primary1Can );
		$mock2 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock2->method( 'getUniqueId' )
			->willReturn( 'primary2' );
		$mock2->method( 'testUserCanAuthenticate' )
			->with( $userName )
			->willReturn( $primary2Can );
		$this->primaryauthMocks = [ $mock1, $mock2 ];

		$this->initializeManager( true );
		$this->assertSame( $expect, $this->manager->userCanAuthenticate( $userName ) );
	}

	public static function provideUserCanAuthenticate() {
		return [
			[ false, false, false ],
			[ true, false, true ],
			[ false, true, true ],
			[ true, true, true ],
		];
	}

	public function testRevokeAccessForUser() {
		$userName = 'TestRevokeAccessForUser';
		$this->initializeManager();

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )
			->willReturn( 'primary' );
		$mock->expects( $this->once() )->method( 'providerRevokeAccessForUser' )
			->with( $userName );
		$this->primaryauthMocks = [ $mock ];

		$this->initializeManager( true );
		$this->logger->setCollect( true );

		$this->manager->revokeAccessForUser( $userName );

		$this->assertSame( [
			[ LogLevel::INFO, 'Revoking access for {user}' ],
		], $this->logger->getBuffer() );
	}

	public function testProviderCreation() {
		$mocks = [
			'pre' => $this->createMock( AbstractPreAuthenticationProvider::class ),
			'primary' => $this->createMock( AbstractPrimaryAuthenticationProvider::class ),
			'secondary' => $this->createMock( AbstractSecondaryAuthenticationProvider::class ),
		];
		foreach ( $mocks as $key => $mock ) {
			$mock->method( 'getUniqueId' )->willReturn( $key );
			$mock->expects( $this->once() )->method( 'init' );
		}
		$this->preauthMocks = [ $mocks['pre'] ];
		$this->primaryauthMocks = [ $mocks['primary'] ];
		$this->secondaryauthMocks = [ $mocks['secondary'] ];

		// Normal operation
		$this->initializeManager();
		$this->assertSame(
			$mocks['primary'],
			$this->managerPriv->getAuthenticationProvider( 'primary' )
		);
		$this->assertSame(
			$mocks['secondary'],
			$this->managerPriv->getAuthenticationProvider( 'secondary' )
		);
		$this->assertSame(
			$mocks['pre'],
			$this->managerPriv->getAuthenticationProvider( 'pre' )
		);
		$this->assertSame(
			[ 'pre' => $mocks['pre'] ],
			$this->managerPriv->getPreAuthenticationProviders()
		);
		$this->assertSame(
			[ 'primary' => $mocks['primary'] ],
			$this->managerPriv->getPrimaryAuthenticationProviders()
		);
		$this->assertSame(
			[ 'secondary' => $mocks['secondary'] ],
			$this->managerPriv->getSecondaryAuthenticationProviders()
		);

		// Duplicate IDs
		$mock1 = $this->createMock( AbstractPreAuthenticationProvider::class );
		$mock2 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock1->method( 'getUniqueId' )->willReturn( 'X' );
		$mock2->method( 'getUniqueId' )->willReturn( 'X' );
		$this->preauthMocks = [ $mock1 ];
		$this->primaryauthMocks = [ $mock2 ];
		$this->secondaryauthMocks = [];
		$this->initializeManager( true );
		try {
			$this->managerPriv->getAuthenticationProvider( 'Y' );
			$this->fail( 'Expected exception not thrown' );
		} catch ( RuntimeException $ex ) {
			$class1 = get_class( $mock1 );
			$class2 = get_class( $mock2 );
			$this->assertSame(
				"Duplicate specifications for id X (classes $class1 and $class2)", $ex->getMessage()
			);
		}

		// Wrong classes
		$mock = $this->getMockForAbstractClass( AuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$class = get_class( $mock );
		$this->preauthMocks = [ $mock ];
		$this->primaryauthMocks = [ $mock ];
		$this->secondaryauthMocks = [ $mock ];
		$this->initializeManager( true );
		try {
			$this->managerPriv->getPreAuthenticationProviders();
			$this->fail( 'Expected exception not thrown' );
		} catch ( RuntimeException $ex ) {
			$this->assertSame(
				"Expected instance of MediaWiki\\Auth\\PreAuthenticationProvider, got $class",
				$ex->getMessage()
			);
		}
		try {
			$this->managerPriv->getPrimaryAuthenticationProviders();
			$this->fail( 'Expected exception not thrown' );
		} catch ( RuntimeException $ex ) {
			$this->assertSame(
				"Expected instance of MediaWiki\\Auth\\PrimaryAuthenticationProvider, got $class",
				$ex->getMessage()
			);
		}
		try {
			$this->managerPriv->getSecondaryAuthenticationProviders();
			$this->fail( 'Expected exception not thrown' );
		} catch ( RuntimeException $ex ) {
			$this->assertSame(
				"Expected instance of MediaWiki\\Auth\\SecondaryAuthenticationProvider, got $class",
				$ex->getMessage()
			);
		}

		// Sorting
		$mock1 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock2 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock3 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock1->method( 'getUniqueId' )->willReturn( 'A' );
		$mock2->method( 'getUniqueId' )->willReturn( 'B' );
		$mock3->method( 'getUniqueId' )->willReturn( 'C' );
		$this->preauthMocks = [];
		$this->primaryauthMocks = [ $mock1, $mock2, $mock3 ];
		$this->secondaryauthMocks = [];
		$this->initializeConfig();
		$config = $this->config->get( MainConfigNames::AuthManagerConfig );

		$this->initializeManager( false );
		$this->assertSame(
			[ 'A' => $mock1, 'B' => $mock2, 'C' => $mock3 ],
			$this->managerPriv->getPrimaryAuthenticationProviders()
		);

		$config['primaryauth']['A']['sort'] = 100;
		$config['primaryauth']['C']['sort'] = -1;
		$this->config->set( MainConfigNames::AuthManagerConfig, $config );
		$this->initializeManager( false );
		$this->assertSame(
			[ 'C' => $mock3, 'B' => $mock2, 'A' => $mock1 ],
			$this->managerPriv->getPrimaryAuthenticationProviders()
		);
	}

	/**
	 * @dataProvider provideSetDefaultUserOptions
	 */
	public function testSetDefaultUserOptions(
		$contLang, $useContextLang, $expectedLang, $expectedVariant
	) {
		$this->setContentLang( $contLang );
		$this->initializeManager( true );
		$context = RequestContext::getMain();
		$reset = new ScopedCallback( [ $context, 'setLanguage' ], [ $context->getLanguage() ] );
		$context->setLanguage( 'de' );

		$user = User::newFromName( self::usernameForCreation() );
		$user->addToDatabase();
		$oldToken = $user->getToken();
		$this->managerPriv->setDefaultUserOptions( $user, $useContextLang );
		$user->saveSettings();
		$this->assertNotEquals( $oldToken, $user->getToken() );
		$this->assertSame(
			$expectedLang,
			$this->userOptionsManager->getOption( $user, 'language' )
		);
		$this->assertSame(
			$expectedVariant,
			$this->userOptionsManager->getOption( $user, 'variant' )
		);
	}

	public static function provideSetDefaultUserOptions() {
		return [
			[ 'zh', false, 'zh', 'zh' ],
			[ 'zh', true, 'de', 'zh' ],
			[ 'fr', true, 'de', 'fr' ],
		];
	}

	public function testForcePrimaryAuthenticationProviders() {
		$mockA = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mockB = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mockB2 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mockA->method( 'getUniqueId' )->willReturn( 'A' );
		$mockB->method( 'getUniqueId' )->willReturn( 'B' );
		$mockB2->method( 'getUniqueId' )->willReturn( 'B' );
		$this->primaryauthMocks = [ $mockA ];

		$this->logger = new TestLogger( true );

		// Test without first initializing the configured providers
		$this->initializeManager();
		$this->expectDeprecationAndContinue( '/AuthManager::forcePrimaryAuthenticationProviders/' );
		$this->manager->forcePrimaryAuthenticationProviders( [ $mockB ], 'testing' );
		$this->assertSame(
			[ 'B' => $mockB ], $this->managerPriv->getPrimaryAuthenticationProviders()
		);
		$this->assertSame( null, $this->managerPriv->getAuthenticationProvider( 'A' ) );
		$this->assertSame( $mockB, $this->managerPriv->getAuthenticationProvider( 'B' ) );
		$this->assertSame( [
			[ LogLevel::WARNING, 'Overriding AuthManager primary authn because testing' ],
		], $this->logger->getBuffer() );
		$this->logger->clearBuffer();

		// Test with first initializing the configured providers
		$this->initializeManager();
		$this->assertSame( $mockA, $this->managerPriv->getAuthenticationProvider( 'A' ) );
		$this->assertSame( null, $this->managerPriv->getAuthenticationProvider( 'B' ) );
		$this->request->getSession()->setSecret( AuthManager::AUTHN_STATE, 'test' );
		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE, 'test' );
		$this->expectDeprecationAndContinue( '/AuthManager::forcePrimaryAuthenticationProviders/' );
		$this->manager->forcePrimaryAuthenticationProviders( [ $mockB ], 'testing' );
		$this->assertSame(
			[ 'B' => $mockB ], $this->managerPriv->getPrimaryAuthenticationProviders()
		);
		$this->assertSame( null, $this->managerPriv->getAuthenticationProvider( 'A' ) );
		$this->assertSame( $mockB, $this->managerPriv->getAuthenticationProvider( 'B' ) );
		$this->assertNull( $this->request->getSession()->getSecret( AuthManager::AUTHN_STATE ) );
		$this->assertNull(
			$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE )
		);
		$this->assertSame( [
			[ LogLevel::WARNING, 'Overriding AuthManager primary authn because testing' ],
			[
				LogLevel::WARNING,
				'PrimaryAuthenticationProviders have already been accessed! I hope nothing breaks.'
			],
		], $this->logger->getBuffer() );
		$this->logger->clearBuffer();

		// Test duplicate IDs
		$this->initializeManager();
		try {
			$this->expectDeprecationAndContinue( '/AuthManager::forcePrimaryAuthenticationProviders/' );
			$this->manager->forcePrimaryAuthenticationProviders( [ $mockB, $mockB2 ], 'testing' );
			$this->fail( 'Expected exception not thrown' );
		} catch ( RuntimeException $ex ) {
			$class1 = get_class( $mockB );
			$class2 = get_class( $mockB2 );
			$this->assertSame(
				"Duplicate specifications for id B (classes $class2 and $class1)", $ex->getMessage()
			);
		}

		// Wrong classes
		$mock = $this->getMockForAbstractClass( AuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$class = get_class( $mock );
		try {
			$this->manager->forcePrimaryAuthenticationProviders( [ $mock ], 'testing' );
			$this->fail( 'Expected exception not thrown' );
		} catch ( RuntimeException $ex ) {
			$this->assertSame(
				"Expected instance of MediaWiki\\Auth\\AbstractPrimaryAuthenticationProvider, got $class",
				$ex->getMessage()
			);
		}
	}

	public function testBeginAuthentication() {
		$this->initializeManager();

		// Immutable session
		[ $provider, $reset ] = $this->getMockSessionProvider( false );
		$this->hook( 'UserLoggedIn', UserLoggedInHook::class, $this->never() );
		$this->request->getSession()->setSecret( AuthManager::AUTHN_STATE, 'test' );
		try {
			$this->manager->beginAuthentication( [], 'http://localhost/' );
			$this->fail( 'Expected exception not thrown' );
		} catch ( LogicException $ex ) {
			$this->assertSame( 'Authentication is not possible now', $ex->getMessage() );
		}
		$this->unhook( 'UserLoggedIn' );
		$this->assertNull( $this->request->getSession()->getSecret( AuthManager::AUTHN_STATE ) );
		ScopedCallback::consume( $reset );
		$this->initializeManager( true );

		// CreatedAccountAuthenticationRequest
		$user = $this->getTestSysop()->getUser();
		$reqs = [
			new CreatedAccountAuthenticationRequest( $user->getId(), $user->getName() )
		];
		$this->hook( 'UserLoggedIn', UserLoggedInHook::class, $this->never() );
		try {
			$this->manager->beginAuthentication( $reqs, 'http://localhost/' );
			$this->fail( 'Expected exception not thrown' );
		} catch ( LogicException $ex ) {
			$this->assertSame(
				'CreatedAccountAuthenticationRequests are only valid on the same AuthManager ' .
					'that created the account',
				$ex->getMessage()
			);
		}
		$this->unhook( 'UserLoggedIn' );

		$this->request->getSession()->clear();
		$this->request->getSession()->setSecret( AuthManager::AUTHN_STATE, 'test' );
		$this->managerPriv->createdAccountAuthenticationRequests = [ $reqs[0] ];
		$this->hook( 'UserLoggedIn', UserLoggedInHook::class, $this->once() )
			->with( $this->callback( static function ( $u ) use ( $user ) {
				return $user->getId() === $u->getId() && $user->getName() === $u->getName();
			} ) );
		$this->hook( 'AuthManagerLoginAuthenticateAudit',
			AuthManagerLoginAuthenticateAuditHook::class, $this->once() );
		$this->logger->setCollect( true );
		$ret = $this->manager->beginAuthentication( $reqs, 'http://localhost/' );
		$this->logger->setCollect( false );
		$this->unhook( 'UserLoggedIn' );
		$this->unhook( 'AuthManagerLoginAuthenticateAudit' );
		$this->assertSame( AuthenticationResponse::PASS, $ret->status );
		$this->assertSame( $user->getName(), $ret->username );
		$this->assertSame( $user->getId(), $this->request->getSessionData( 'AuthManager:lastAuthId' ) );
		// FIXME: Avoid relying on implicit amounts of time elapsing.
		$this->assertEqualsWithDelta(
			time(),
			$this->request->getSessionData( 'AuthManager:lastAuthTimestamp' ),
			1,
			'timestamp ±1'
		);
		$this->assertNull( $this->request->getSession()->getSecret( AuthManager::AUTHN_STATE ) );
		$this->assertSame( $user->getId(), $this->request->getSession()->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::INFO, 'Logging in {user} after account creation' ],
		], $this->logger->getBuffer() );
	}

	public function testCreateFromLogin() {
		$user = $this->getTestSysop()->getUser();
		$req1 = $this->createMock( AuthenticationRequest::class );
		$req2 = $this->createMock( AuthenticationRequest::class );
		$req3 = $this->createMock( AuthenticationRequest::class );
		$userReq = new UsernameAuthenticationRequest;
		$userReq->username = 'UTDummy';

		$req1->returnToUrl = 'http://localhost/';
		$req2->returnToUrl = 'http://localhost/';
		$req3->returnToUrl = 'http://localhost/';
		$req3->username = 'UTDummy';
		$userReq->returnToUrl = 'http://localhost/';

		// Passing one into beginAuthentication(), and an immediate FAIL
		$primary = $this->getMockForAbstractClass( AbstractPrimaryAuthenticationProvider::class );
		$this->primaryauthMocks = [ $primary ];
		$this->initializeManager( true );
		$res = AuthenticationResponse::newFail( wfMessage( 'foo' ) );
		$res->createRequest = $req1;
		$primary->method( 'beginPrimaryAuthentication' )
			->willReturn( $res );
		$createReq = new CreateFromLoginAuthenticationRequest(
			null, [ $req2->getUniqueId() => $req2 ]
		);
		$this->logger->setCollect( true );
		$ret = $this->manager->beginAuthentication( [ $createReq ], 'http://localhost/' );
		$this->logger->setCollect( false );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertInstanceOf( CreateFromLoginAuthenticationRequest::class, $ret->createRequest );
		$this->assertSame( $req1, $ret->createRequest->createRequest );
		$this->assertEquals( [ $req2->getUniqueId() => $req2 ], $ret->createRequest->maybeLink );

		// UI, then FAIL in beginAuthentication()
		$primary = $this->getMockBuilder( AbstractPrimaryAuthenticationProvider::class )
			->onlyMethods( [ 'continuePrimaryAuthentication' ] )
			->getMockForAbstractClass();
		$this->primaryauthMocks = [ $primary ];
		$this->initializeManager( true );
		$primary->method( 'beginPrimaryAuthentication' )
			->willReturn( AuthenticationResponse::newUI( [ $req1 ], wfMessage( 'foo' ) ) );
		$res = AuthenticationResponse::newFail( wfMessage( 'foo' ) );
		$res->createRequest = $req2;
		$primary->method( 'continuePrimaryAuthentication' )
			->willReturn( $res );
		$this->logger->setCollect( true );
		$ret = $this->manager->beginAuthentication( [], 'http://localhost/' );
		$this->assertSame( AuthenticationResponse::UI, $ret->status );
		$ret = $this->manager->continueAuthentication( [] );
		$this->logger->setCollect( false );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertInstanceOf( CreateFromLoginAuthenticationRequest::class, $ret->createRequest );
		$this->assertSame( $req2, $ret->createRequest->createRequest );
		$this->assertEquals( [], $ret->createRequest->maybeLink );

		// Pass into beginAccountCreation(), see that maybeLink and createRequest get copied
		$primary = $this->getMockForAbstractClass( AbstractPrimaryAuthenticationProvider::class );
		$this->primaryauthMocks = [ $primary ];
		$this->initializeManager( true );
		$createReq = new CreateFromLoginAuthenticationRequest( $req3, [ $req2 ] );
		$createReq->returnToUrl = 'http://localhost/';
		$createReq->username = 'UTDummy';
		$res = AuthenticationResponse::newUI( [ $req1 ], wfMessage( 'foo' ) );
		$primary->method( 'beginPrimaryAccountCreation' )
			->with( $this->anything(), $this->anything(), [ $userReq, $createReq, $req3 ] )
			->willReturn( $res );
		$primary->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$this->logger->setCollect( true );
		$ret = $this->manager->beginAccountCreation(
			$user, [ $userReq, $createReq ], 'http://localhost/'
		);
		$this->logger->setCollect( false );
		$this->assertSame( AuthenticationResponse::UI, $ret->status );
		$state = $this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE );
		$this->assertNotNull( $state );
		$this->assertEquals( [ $userReq, $createReq, $req3 ], $state['reqs'] );
		$this->assertEquals( [ $req2 ], $state['maybeLink'] );
	}

	/**
	 * @dataProvider provideAuthentication
	 * @param StatusValue $preResponse
	 * @param array<AuthenticationResponse|Exception> $primaryResponses
	 * @param array<AuthenticationResponse|Exception> $secondaryResponses
	 * @param array<AuthenticationResponse|Exception> $managerResponses
	 * @param bool $link Whether the primary authentication provider is a "link" provider
	 */
	public function testAuthentication(
		StatusValue $preResponse, array $primaryResponses, array $secondaryResponses,
		array $managerResponses, $link = false
	) {
		$this->initializeManager();
		$user = $this->getTestSysop()->getUser();
		$id = $user->getId();
		$name = $user->getName();
		// Hack: replace placeholder usernames with that of the test user. A better solution would be to instantiate
		// all responses here, only providing constructor arguments (like the status) from the data provider.
		$responseArrays = [ $primaryResponses, $secondaryResponses, $managerResponses ];
		foreach ( $responseArrays as $respArray ) {
			foreach ( $respArray as $resp ) {
				if ( $resp instanceof AuthenticationResponse && $resp->username === 'PLACEHOLDER' ) {
					$resp->username = $name;
				}
			}
		}

		// Set up lots of mocks...
		$req = new RememberMeAuthenticationRequest;
		$req->rememberMe = (bool)rand( 0, 1 );
		$mocks = [];
		foreach ( [ 'pre', 'primary', 'secondary' ] as $key ) {
			$class = ucfirst( $key ) . 'AuthenticationProvider';
			$mocks[$key] = $this->getMockBuilder( "MediaWiki\\Auth\\Abstract$class" )
				->setMockClassName( "MockAbstract$class" )
				->getMock();
			$mocks[$key]->method( 'getUniqueId' )
				->willReturn( $key );
			$mocks[$key . '2'] = $this->createMock( "MediaWiki\\Auth\\Abstract$class" );
			$mocks[$key . '2']->method( 'getUniqueId' )
				->willReturn( $key . '2' );
			$mocks[$key . '3'] = $this->createMock( "MediaWiki\\Auth\\Abstract$class" );
			$mocks[$key . '3']->method( 'getUniqueId' )
				->willReturn( $key . '3' );
		}
		foreach ( $mocks as $mock ) {
			$mock->method( 'getAuthenticationRequests' )
				->willReturn( [] );
		}

		$mocks['pre']->expects( $this->once() )->method( 'testForAuthentication' )
			->willReturnCallback( function ( $reqs ) use ( $req, $preResponse ) {
				$this->assertContains( $req, $reqs );
				return $preResponse;
			} );

		$ct = count( $primaryResponses );
		$callback = $this->returnCallback( function ( $reqs ) use ( $req, &$primaryResponses ) {
			$this->assertContains( $req, $reqs );
			return array_shift( $primaryResponses );
		} );
		$mocks['primary']->expects( $this->exactly( min( 1, $ct ) ) )
			->method( 'beginPrimaryAuthentication' )
			->will( $callback );
		$mocks['primary']->expects( $this->exactly( max( 0, $ct - 1 ) ) )
			->method( 'continuePrimaryAuthentication' )
			->will( $callback );
		if ( $link ) {
			$mocks['primary']->method( 'accountCreationType' )
				->willReturn( PrimaryAuthenticationProvider::TYPE_LINK );
		}

		$ct = count( $secondaryResponses );
		$callback = $this->returnCallback( function ( $user, $reqs ) use ( $id, $name, $req, &$secondaryResponses ) {
			$this->assertSame( $id, $user->getId() );
			$this->assertSame( $name, $user->getName() );
			$this->assertContains( $req, $reqs );
			return array_shift( $secondaryResponses );
		} );
		$mocks['secondary']->expects( $this->exactly( min( 1, $ct ) ) )
			->method( 'beginSecondaryAuthentication' )
			->will( $callback );
		$mocks['secondary']->expects( $this->exactly( max( 0, $ct - 1 ) ) )
			->method( 'continueSecondaryAuthentication' )
			->will( $callback );

		$abstain = AuthenticationResponse::newAbstain();
		$mocks['pre2']->expects( $this->atMost( 1 ) )->method( 'testForAuthentication' )
			->willReturn( StatusValue::newGood() );
		$mocks['primary2']->expects( $this->atMost( 1 ) )->method( 'beginPrimaryAuthentication' )
				->willReturn( $abstain );
		$mocks['primary2']->expects( $this->never() )->method( 'continuePrimaryAuthentication' );
		$mocks['secondary2']->expects( $this->atMost( 1 ) )->method( 'beginSecondaryAuthentication' )
				->willReturn( $abstain );
		$mocks['secondary2']->expects( $this->never() )->method( 'continueSecondaryAuthentication' );
		$mocks['secondary3']->expects( $this->atMost( 1 ) )->method( 'beginSecondaryAuthentication' )
				->willReturn( $abstain );
		$mocks['secondary3']->expects( $this->never() )->method( 'continueSecondaryAuthentication' );

		$this->preauthMocks = [ $mocks['pre'], $mocks['pre2'] ];
		$this->primaryauthMocks = [ $mocks['primary'], $mocks['primary2'] ];
		$this->secondaryauthMocks = [
			$mocks['secondary3'], $mocks['secondary'], $mocks['secondary2'],
			// So linking happens
			new ConfirmLinkSecondaryAuthenticationProvider,
		];
		$this->initializeManager( true );
		$this->logger->setCollect( true );

		$constraint = Assert::logicalOr(
			$this->equalTo( AuthenticationResponse::PASS ),
			$this->equalTo( AuthenticationResponse::FAIL )
		);
		$providers = array_filter(
			array_merge(
				$this->preauthMocks, $this->primaryauthMocks, $this->secondaryauthMocks
			),
			static function ( $p ) {
				return is_callable( [ $p, 'expects' ] );
			}
		);
		foreach ( $providers as $p ) {
			DynamicPropertyTestHelper::setDynamicProperty( $p, 'postCalled', false );
			$p->expects( $this->atMost( 1 ) )->method( 'postAuthentication' )
				->willReturnCallback( function ( $userArg, $response ) use ( $user, $constraint, $p ) {
					if ( $userArg !== null ) {
						$this->assertInstanceOf( User::class, $userArg );
						$this->assertSame( $user->getName(), $userArg->getName() );
					}
					$this->assertInstanceOf( AuthenticationResponse::class, $response );
					$this->assertThat( $response->status, $constraint );
					DynamicPropertyTestHelper::setDynamicProperty( $p, 'postCalled', $response->status );
				} );
		}

		$session = $this->request->getSession();
		$session->setRememberUser( !$req->rememberMe );

		foreach ( $managerResponses as $i => $response ) {
			$success = $response instanceof AuthenticationResponse &&
				$response->status === AuthenticationResponse::PASS;
			if ( $success ) {
				$this->hook( 'UserLoggedIn', UserLoggedInHook::class, $this->once() )
					->with( $this->callback( static function ( $user ) use ( $id, $name ) {
						return $user->getId() === $id && $user->getName() === $name;
					} ) );
			} else {
				$this->hook( 'UserLoggedIn', UserLoggedInHook::class, $this->never() );
			}
			if ( $success || (
					$response instanceof AuthenticationResponse &&
					$response->status === AuthenticationResponse::FAIL &&
					$response->message->getKey() !== 'authmanager-authn-not-in-progress' &&
					$response->message->getKey() !== 'authmanager-authn-no-primary'
				)
			) {
				$this->hook( 'AuthManagerLoginAuthenticateAudit',
					AuthManagerLoginAuthenticateAuditHook::class, $this->once() );
			} else {
				$this->hook( 'AuthManagerLoginAuthenticateAudit',
					AuthManagerLoginAuthenticateAuditHook::class, $this->never() );
			}

			try {
				if ( !$i ) {
					$ret = $this->manager->beginAuthentication( [ $req ], 'http://localhost/' );
				} else {
					$ret = $this->manager->continueAuthentication( [ $req ] );
				}
				if ( $response instanceof Exception ) {
					$this->fail( 'Expected exception not thrown', "Response $i" );
				}
			} catch ( Exception $ex ) {
				if ( !$response instanceof Exception ) {
					throw $ex;
				}
				$this->assertEquals( $response->getMessage(), $ex->getMessage(), "Response $i, exception" );
				$this->assertNull( $session->getSecret( AuthManager::AUTHN_STATE ),
					"Response $i, exception, session state" );
				$this->unhook( 'UserLoggedIn' );
				$this->unhook( 'AuthManagerLoginAuthenticateAudit' );
				return;
			}

			$this->unhook( 'UserLoggedIn' );
			$this->unhook( 'AuthManagerLoginAuthenticateAudit' );

			$this->assertSame( 'http://localhost/', $req->returnToUrl );

			$ret->message = $this->message( $ret->message );
			$this->assertResponseEquals( $response, $ret, "Response $i, response" );
			if ( $success ) {
				$this->assertSame( $id, $session->getUser()->getId(),
					"Response $i, authn" );
			} else {
				$this->assertSame( 0, $session->getUser()->getId(),
					"Response $i, authn" );
			}
			if ( $success || $response->status === AuthenticationResponse::FAIL ) {
				$this->assertNull( $session->getSecret( AuthManager::AUTHN_STATE ),
					"Response $i, session state" );
				foreach ( $providers as $p ) {
					$this->assertSame( $response->status, DynamicPropertyTestHelper::getDynamicProperty( $p, 'postCalled' ),
						"Response $i, post-auth callback called" );
				}
			} else {
				$this->assertNotNull( $session->getSecret( AuthManager::AUTHN_STATE ),
					"Response $i, session state" );
				foreach ( $ret->neededRequests as $neededReq ) {
					$this->assertEquals( AuthManager::ACTION_LOGIN, $neededReq->action,
						"Response $i, neededRequest action" );
				}
				$this->assertEquals(
					$ret->neededRequests,
					$this->manager->getAuthenticationRequests( AuthManager::ACTION_LOGIN_CONTINUE ),
					"Response $i, continuation check"
				);
				foreach ( $providers as $p ) {
					$this->assertFalse( DynamicPropertyTestHelper::getDynamicProperty( $p, 'postCalled' ), "Response $i, post-auth callback not called" );
				}
			}

			$state = $session->getSecret( AuthManager::AUTHN_STATE );
			$maybeLink = $state['maybeLink'] ?? [];
			if ( $link && $response->status === AuthenticationResponse::RESTART ) {
				$this->assertEquals(
					$response->createRequest->maybeLink,
					$maybeLink,
					"Response $i, maybeLink"
				);
			} else {
				$this->assertEquals( [], $maybeLink, "Response $i, maybeLink" );
			}
		}

		if ( $success ) {
			$this->assertSame( $req->rememberMe, $session->shouldRememberUser(),
				'rememberMe checkbox had effect' );
		} else {
			$this->assertNotSame( $req->rememberMe, $session->shouldRememberUser(),
				'rememberMe checkbox wasn\'t applied' );
		}
	}

	public function provideAuthentication() {
		$rememberReq = new RememberMeAuthenticationRequest;
		$rememberReq->action = AuthManager::ACTION_LOGIN;

		$req = $this->getMockForAbstractClass( AuthenticationRequest::class );
		$restartResponse = AuthenticationResponse::newRestart(
			$this->message( 'authmanager-authn-no-local-user' )
		);
		$restartResponse->neededRequests = [ $rememberReq ];

		$restartResponse2Pass = AuthenticationResponse::newPass( null );
		$restartResponse2Pass->linkRequest = $req;
		$restartResponse2 = AuthenticationResponse::newRestart(
			$this->message( 'authmanager-authn-no-local-user-link' )
		);
		$restartResponse2->createRequest = new CreateFromLoginAuthenticationRequest(
			null, [ $req->getUniqueId() => $req ]
		);
		$restartResponse2->createRequest->action = AuthManager::ACTION_LOGIN;
		$restartResponse2->neededRequests = [ $rememberReq, $restartResponse2->createRequest ];

		// Hack: use a placeholder that will be replaced with the actual username in the test method.
		$userNamePlaceholder = 'PLACEHOLDER';

		return [
			'Failure in pre-auth' => [
				StatusValue::newFatal( 'fail-from-pre' ),
				[],
				[],
				[
					AuthenticationResponse::newFail( $this->message( 'fail-from-pre' ) ),
					AuthenticationResponse::newFail(
						$this->message( 'authmanager-authn-not-in-progress' )
					),
				]
			],
			'Failure in primary' => [
				StatusValue::newGood(),
				$tmp = [
					AuthenticationResponse::newFail( $this->message( 'fail-from-primary' ) ),
				],
				[],
				$tmp
			],
			'All primary abstain' => [
				StatusValue::newGood(),
				[
					AuthenticationResponse::newAbstain(),
				],
				[],
				[
					AuthenticationResponse::newFail( $this->message( 'authmanager-authn-no-primary' ) )
				]
			],
			'Primary UI, then redirect, then fail' => [
				StatusValue::newGood(),
				$tmp = [
					AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ),
					AuthenticationResponse::newRedirect( [ $req ], '/foo.html', [ 'foo' => 'bar' ] ),
					AuthenticationResponse::newFail( $this->message( 'fail-in-primary-continue' ) ),
				],
				[],
				$tmp
			],
			'Primary redirect, then abstain' => [
				StatusValue::newGood(),
				[
					$tmp = AuthenticationResponse::newRedirect(
						[ $req ], '/foo.html', [ 'foo' => 'bar' ]
					),
					AuthenticationResponse::newAbstain(),
				],
				[],
				[
					$tmp,
					new DomainException(
						'MockAbstractPrimaryAuthenticationProvider::continuePrimaryAuthentication() returned ABSTAIN'
					)
				]
			],
			'Primary UI, then pass with no local user' => [
				StatusValue::newGood(),
				[
					$tmp = AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ),
					AuthenticationResponse::newPass( null ),
				],
				[],
				[
					$tmp,
					$restartResponse,
				]
			],
			'Primary UI, then pass with no local user (link type)' => [
				StatusValue::newGood(),
				[
					$tmp = AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ),
					$restartResponse2Pass,
				],
				[],
				[
					$tmp,
					$restartResponse2,
				],
				true
			],
			'Primary pass with invalid username' => [
				StatusValue::newGood(),
				[
					AuthenticationResponse::newPass( '<>' ),
				],
				[],
				[
					new DomainException(
						'MockAbstractPrimaryAuthenticationProvider returned an invalid username: <>'
					),
				]
			],
			'Secondary fail' => [
				StatusValue::newGood(),
				[
					AuthenticationResponse::newPass( $userNamePlaceholder ),
				],
				$tmp = [
					AuthenticationResponse::newFail( $this->message( 'fail-in-secondary' ) ),
				],
				$tmp
			],
			'Secondary UI, then abstain' => [
				StatusValue::newGood(),
				[
					AuthenticationResponse::newPass( $userNamePlaceholder ),
				],
				[
					$tmp = AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ),
					AuthenticationResponse::newAbstain()
				],
				[
					$tmp,
					AuthenticationResponse::newPass( $userNamePlaceholder ),
				]
			],
			'Secondary pass' => [
				StatusValue::newGood(),
				[
					AuthenticationResponse::newPass( $userNamePlaceholder ),
				],
				[
					AuthenticationResponse::newPass()
				],
				[
					AuthenticationResponse::newPass( $userNamePlaceholder ),
				]
			],
		];
	}

	/**
	 * @dataProvider provideUserExists
	 * @param bool $primary1Exists
	 * @param bool $primary2Exists
	 * @param bool $expect
	 */
	public function testUserExists( $primary1Exists, $primary2Exists, $expect ) {
		$userName = 'TestUserExists';
		$mock1 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock1->method( 'getUniqueId' )
			->willReturn( 'primary1' );
		$mock1->method( 'testUserExists' )
			->with( $userName )
			->willReturn( $primary1Exists );
		$mock2 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock2->method( 'getUniqueId' )
			->willReturn( 'primary2' );
		$mock2->method( 'testUserExists' )
			->with( $userName )
			->willReturn( $primary2Exists );
		$this->primaryauthMocks = [ $mock1, $mock2 ];

		$this->initializeManager( true );
		$this->assertSame( $expect, $this->manager->userExists( $userName ) );
	}

	public static function provideUserExists() {
		return [
			[ false, false, false ],
			[ true, false, true ],
			[ false, true, true ],
			[ true, true, true ],
		];
	}

	/**
	 * @dataProvider provideAllowsAuthenticationDataChange
	 * @param StatusValue $primaryReturn
	 * @param StatusValue $secondaryReturn
	 * @param Status $expect
	 */
	public function testAllowsAuthenticationDataChange( $primaryReturn, $secondaryReturn, $expect ) {
		$req = $this->getMockForAbstractClass( AuthenticationRequest::class );

		$mock1 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock1->method( 'getUniqueId' )->willReturn( '1' );
		$mock1->method( 'providerAllowsAuthenticationDataChange' )
			->with( $req )
			->willReturn( $primaryReturn );
		$mock2 = $this->createMock( AbstractSecondaryAuthenticationProvider::class );
		$mock2->method( 'getUniqueId' )->willReturn( '2' );
		$mock2->method( 'providerAllowsAuthenticationDataChange' )
			->with( $req )
			->willReturn( $secondaryReturn );

		$this->primaryauthMocks = [ $mock1 ];
		$this->secondaryauthMocks = [ $mock2 ];
		$this->initializeManager( true );
		$this->assertEquals( $expect, $this->manager->allowsAuthenticationDataChange( $req ) );
	}

	public static function provideAllowsAuthenticationDataChange() {
		$ignored = Status::newGood( 'ignored' );
		$ignored->warning( 'authmanager-change-not-supported' );

		$okFromPrimary = StatusValue::newGood();
		$okFromPrimary->warning( 'warning-from-primary' );
		$okFromSecondary = StatusValue::newGood();
		$okFromSecondary->warning( 'warning-from-secondary' );

		$throttledMailPassword = StatusValue::newFatal( 'throttled-mailpassword' );

		return [
			[
				StatusValue::newGood(),
				StatusValue::newGood(),
				Status::newGood(),
			],
			[
				StatusValue::newGood(),
				StatusValue::newGood( 'ignore' ),
				Status::newGood(),
			],
			[
				StatusValue::newGood( 'ignored' ),
				StatusValue::newGood(),
				Status::newGood(),
			],
			[
				StatusValue::newGood( 'ignored' ),
				StatusValue::newGood( 'ignored' ),
				$ignored,
			],
			[
				StatusValue::newFatal( 'fail from primary' ),
				StatusValue::newGood(),
				Status::newFatal( 'fail from primary' ),
			],
			[
				$okFromPrimary,
				StatusValue::newGood(),
				Status::wrap( $okFromPrimary ),
			],
			[
				StatusValue::newGood(),
				StatusValue::newFatal( 'fail from secondary' ),
				Status::newFatal( 'fail from secondary' ),
			],
			[
				StatusValue::newGood(),
				$okFromSecondary,
				Status::wrap( $okFromSecondary ),
			],
			[
				StatusValue::newGood(),
				$throttledMailPassword,
				Status::newGood( 'throttled-mailpassword' ),
			]
		];
	}

	public function testChangeAuthenticationData() {
		$req = $this->getMockForAbstractClass( AuthenticationRequest::class );
		$req->username = 'TestChangeAuthenticationData';

		$mock1 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock1->method( 'getUniqueId' )->willReturn( '1' );
		$mock1->expects( $this->once() )->method( 'providerChangeAuthenticationData' )
			->with( $req );
		$mock2 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock2->method( 'getUniqueId' )->willReturn( '2' );
		$mock2->expects( $this->once() )->method( 'providerChangeAuthenticationData' )
			->with( $req );

		$this->primaryauthMocks = [ $mock1, $mock2 ];
		$this->initializeManager( true );
		$this->logger->setCollect( true );
		$this->manager->changeAuthenticationData( $req );
		$this->assertSame( [
			[ LogLevel::INFO, 'Changing authentication data for {user} class {what}' ],
		], $this->logger->getBuffer() );
	}

	public function testCanCreateAccounts() {
		$types = [
			PrimaryAuthenticationProvider::TYPE_CREATE => true,
			PrimaryAuthenticationProvider::TYPE_LINK => true,
			PrimaryAuthenticationProvider::TYPE_NONE => false,
		];

		foreach ( $types as $type => $can ) {
			$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
			$mock->method( 'getUniqueId' )->willReturn( $type );
			$mock->method( 'accountCreationType' )
				->willReturn( $type );
			$this->primaryauthMocks = [ $mock ];
			$this->initializeManager( true );
			$this->assertSame( $can, $this->manager->canCreateAccounts(), $type );
		}
	}

	/**
	 * @covers \MediaWiki\Auth\AuthManager::probablyCanCreateAccount()
	 */
	public function testProbablyCanCreateAccount() {
		$this->setGroupPermissions( '*', 'createaccount', true );
		$this->initializeManager( true );
		$this->assertEquals(
			StatusValue::newGood(),
			$this->manager->probablyCanCreateAccount( new User )
		);
	}

	/**
	 * @covers \MediaWiki\Auth\AuthManager::authorizeCreateAccount()
	 */
	public function testAuthorizeCreateAccount_anon() {
		$this->setGroupPermissions( '*', 'createaccount', true );
		$this->initializeManager( true );
		$this->assertEquals(
			StatusValue::newGood(),
			$this->manager->authorizeCreateAccount( new User )
		);
	}

	/**
	 * @covers \MediaWiki\Auth\AuthManager::authorizeCreateAccount()
	 */
	public function testAuthorizeCreateAccount_anonNotAllowed() {
		$this->setGroupPermissions( '*', 'createaccount', false );
		$this->initializeManager( true );
		$status = $this->manager->authorizeCreateAccount( new User );
		$this->assertStatusError( 'badaccess-groups', $status );
	}

	/**
	 * @covers \MediaWiki\Auth\AuthManager::authorizeCreateAccount()
	 */
	public function testAuthorizeCreateAccount_readOnly() {
		$this->initializeManager( true );
		$readOnlyMode = $this->getServiceContainer()->getReadOnlyMode();
		$readOnlyMode->setReason( 'Because' );
		$this->assertEquals(
			StatusValue::newFatal( wfMessage( 'readonlytext', 'Because' ) ),
			$this->manager->authorizeCreateAccount( new User )
		);
		$readOnlyMode->setReason( false );
	}

	/**
	 * @covers \MediaWiki\Auth\AuthManager::authorizeCreateAccount()
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserBlock()
	 */
	public function testAuthorizeCreateAccount_blocked() {
		$this->initializeManager( true );

		$user = User::newFromName( 'UTBlockee' );
		if ( $user->getId() == 0 ) {
			$user->addToDatabase();
			TestUser::setPasswordForUser( $user, 'UTBlockeePassword' );
			$user->saveSettings();
		}
		$blockStore = $this->getServiceContainer()->getDatabaseBlockStore();
		$blockOptions = [
			'address' => $user,
			'by' => $this->getTestSysop()->getUser(),
			'reason' => __METHOD__,
			'expiry' => time() + 100500,
			'createAccount' => true,
		];
		$block = new DatabaseBlock( $blockOptions );
		$blockStore->insertBlock( $block );
		$this->resetServices();
		$this->initializeManager( true );
		$status = $this->manager->authorizeCreateAccount( $user );
		$this->assertStatusError( 'blockedtext', $status );
	}

	/**
	 * @covers \MediaWiki\Auth\AuthManager::authorizeCreateAccount()
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserBlock()
	 */
	public function testAuthorizeCreateAccount_ipBlocked() {
		$this->setGroupPermissions( '*', 'createaccount', true );
		$this->initializeManager( true );
		$blockStore = $this->getServiceContainer()->getDatabaseBlockStore();
		$blockOptions = [
			'address' => '127.0.0.0/24',
			'by' => $this->getTestSysop()->getUser(),
			'reason' => __METHOD__,
			'expiry' => time() + 100500,
			'createAccount' => true,
			'sitewide' => false,
		];
		$block = new DatabaseBlock( $blockOptions );
		$blockStore->insertBlock( $block );
		$status = $this->manager->authorizeCreateAccount( new User );
		$this->assertStatusError( 'blockedtext-partial', $status );
	}

	/**
	 * @covers \MediaWiki\Auth\AuthManager::authorizeCreateAccount()
	 */
	public function testAuthorizeCreateAccount_DNSBlacklist() {
		$this->overrideConfigValues( [
			MainConfigNames::EnableDnsBlacklist => true,
			MainConfigNames::DnsBlacklistUrls => [
				'localhost',
			],
			MainConfigNames::ProxyWhitelist => [],
		] );
		$this->initializeManager( true );

		// For User::getBlockedStatus()
		$this->setService( 'BlockManager', $this->blockManager );

		$status = $this->manager->authorizeCreateAccount( new User );
		$this->assertStatusError( 'sorbs_create_account_reason', $status );

		$this->overrideConfigValue( MainConfigNames::ProxyWhitelist, [ '127.0.0.1' ] );
		$this->initializeManager( true );
		$this->setService( 'BlockManager', $this->blockManager );
		$status = $this->manager->authorizeCreateAccount( new User );
		$this->assertStatusGood( $status );
	}

	/**
	 * @covers \MediaWiki\Auth\AuthManager::authorizeCreateAccount()
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserBlock()
	 */
	public function testAuthorizeCreateAccount_ipIsBlockedByUserNot() {
		$this->initializeManager( true );

		$user = User::newFromName( 'UTBlockee' );
		if ( $user->getId() == 0 ) {
			$user->addToDatabase();
			TestUser::setPasswordForUser( $user, 'UTBlockeePassword' );
			$user->saveSettings();
		}
		$blockStore = $this->getServiceContainer()->getDatabaseBlockStore();
		$blockOptions = [
			'address' => $user,
			'by' => $this->getTestSysop()->getUser(),
			'reason' => __METHOD__,
			'expiry' => time() + 100500,
			'createAccount' => false,
		];
		$block = new DatabaseBlock( $blockOptions );
		$blockStore->insertBlock( $block );

		$blockOptions = [
			'address' => '127.0.0.0/24',
			'by' => $this->getTestSysop()->getUser(),
			'reason' => __METHOD__,
			'expiry' => time() + 100500,
			'createAccount' => true,
			'sitewide' => false,
		];
		$block = new DatabaseBlock( $blockOptions );
		$blockStore->insertBlock( $block );

		$this->resetServices();
		$this->initializeManager( true );
		$status = $this->manager->authorizeCreateAccount( $user );
		$this->assertStatusError( 'blockedtext-partial', $status );
	}

	/**
	 * @param string $uniq
	 * @return string
	 */
	private static function usernameForCreation( $uniq = '' ) {
		$i = 0;
		do {
			$username = "UTAuthManagerTestAccountCreation" . $uniq . ++$i;
		} while ( User::newFromName( $username )->getId() !== 0 );
		return $username;
	}

	public function testCanCreateAccount() {
		$username = self::usernameForCreation();
		$this->initializeManager();

		$this->assertEquals(
			Status::newFatal( 'authmanager-create-disabled' ),
			$this->manager->canCreateAccount( $username )
		);

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( true );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newGood() );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$this->assertEquals(
			Status::newFatal( 'userexists' ),
			$this->manager->canCreateAccount( $username )
		);

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( false );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newGood() );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$this->assertEquals(
			Status::newFatal( 'noname' ),
			$this->manager->canCreateAccount( $username . '<>' )
		);

		$existingUserName = $this->getTestSysop()->getUserIdentity()->getName();
		$this->assertEquals(
			Status::newFatal( 'userexists' ),
			$this->manager->canCreateAccount( $existingUserName )
		);

		$this->assertEquals(
			Status::newGood(),
			$this->manager->canCreateAccount( $username )
		);

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( false );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newFatal( 'fail' ) );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$this->assertEquals(
			Status::newFatal( 'fail' ),
			$this->manager->canCreateAccount( $username )
		);
	}

	public function testBeginAccountCreation() {
		$creator = $this->getTestSysop()->getUser();
		$userReq = new UsernameAuthenticationRequest;
		$this->logger = new TestLogger( false, static function ( $message, $level ) {
			return $level === LogLevel::DEBUG ? null : $message;
		} );
		$this->initializeManager();

		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE, 'test' );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		try {
			$this->manager->beginAccountCreation(
				$creator, [], 'http://localhost/'
			);
			$this->fail( 'Expected exception not thrown' );
		} catch ( LogicException $ex ) {
			$this->assertEquals( 'Account creation is not possible', $ex->getMessage() );
		}
		$this->unhook( 'LocalUserCreated' );
		$this->assertNull(
			$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE )
		);

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( true );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newGood() );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->beginAccountCreation( $creator, [], 'http://localhost/' );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'noname', $ret->message->getKey() );

		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$userReq->username = self::usernameForCreation();
		$userReq2 = new UsernameAuthenticationRequest;
		$userReq2->username = $userReq->username . 'X';
		$ret = $this->manager->beginAccountCreation(
			$creator, [ $userReq, $userReq2 ], 'http://localhost/'
		);
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'noname', $ret->message->getKey() );

		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$readOnlyMode = $this->getServiceContainer()->getReadOnlyMode();
		$readOnlyMode->setReason( 'Because' );
		$userReq->username = self::usernameForCreation();
		$ret = $this->manager->beginAccountCreation( $creator, [ $userReq ], 'http://localhost/' );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'readonlytext', $ret->message->getKey() );
		$this->assertSame( [ 'Because' ], $ret->message->getParams() );
		$readOnlyMode->setReason( false );

		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$userReq->username = self::usernameForCreation();
		$ret = $this->manager->beginAccountCreation( $creator, [ $userReq ], 'http://localhost/' );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'userexists', $ret->message->getKey() );

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( false );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newFatal( 'fail' ) );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$userReq->username = self::usernameForCreation();
		$ret = $this->manager->beginAccountCreation( $creator, [ $userReq ], 'http://localhost/' );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'fail', $ret->message->getKey() );

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( false );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newGood() );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$userReq->username = self::usernameForCreation() . '<>';
		$ret = $this->manager->beginAccountCreation( $creator, [ $userReq ], 'http://localhost/' );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'noname', $ret->message->getKey() );

		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$userReq->username = $creator->getName();
		$ret = $this->manager->beginAccountCreation( $creator, [ $userReq ], 'http://localhost/' );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'userexists', $ret->message->getKey() );

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( false );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newGood() );
		$mock->method( 'testForAccountCreation' )
			->willReturn( StatusValue::newFatal( 'fail' ) );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$req = $this->getMockBuilder( UserDataAuthenticationRequest::class )
			->onlyMethods( [ 'populateUser' ] )
			->getMock();
		$req->method( 'populateUser' )
			->willReturn( StatusValue::newFatal( 'populatefail' ) );
		$userReq->username = self::usernameForCreation();
		$ret = $this->manager->beginAccountCreation(
			$creator, [ $userReq, $req ], 'http://localhost/'
		);
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'populatefail', $ret->message->getKey() );

		$req = new UserDataAuthenticationRequest;
		$userReq->username = self::usernameForCreation();

		$ret = $this->manager->beginAccountCreation(
			$creator, [ $userReq, $req ], 'http://localhost/'
		);
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'fail', $ret->message->getKey() );

		$this->manager->beginAccountCreation(
			User::newFromName( $userReq->username ), [ $userReq, $req ], 'http://localhost/'
		);
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'fail', $ret->message->getKey() );
	}

	public function testContinueAccountCreation() {
		$creator = $this->getTestSysop()->getUser();
		$username = self::usernameForCreation();
		$this->logger = new TestLogger( false, static function ( $message, $level ) {
			return $level === LogLevel::DEBUG ? null : $message;
		} );
		$this->initializeManager();

		$session = [
			'userid' => 0,
			'username' => $username,
			'creatorid' => 0,
			'creatorname' => $username,
			'reqs' => [],
			'primary' => null,
			'primaryResponse' => null,
			'secondary' => [],
			'ranPreTests' => true,
		];

		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		try {
			$this->manager->continueAccountCreation( [] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( LogicException $ex ) {
			$this->assertEquals( 'Account creation is not possible', $ex->getMessage() );
		}
		$this->unhook( 'LocalUserCreated' );

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( false );
		$mock->method( 'beginPrimaryAccountCreation' )
			->willReturn( AuthenticationResponse::newFail( $this->message( 'fail' ) ) );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE, null );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->continueAccountCreation( [] );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'authmanager-create-not-in-progress', $ret->message->getKey() );

		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE,
			[ 'username' => "$username<>" ] + $session );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->continueAccountCreation( [] );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'noname', $ret->message->getKey() );
		$this->assertNull(
			$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE )
		);

		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE, $session );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$cache = $this->objectCacheFactory->getLocalClusterInstance();
		$lock = $cache->getScopedLock( $cache->makeGlobalKey( 'account', md5( $username ) ) );
		$ret = $this->manager->continueAccountCreation( [] );
		unset( $lock );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'usernameinprogress', $ret->message->getKey() );
		// This error shouldn't remove the existing session, because the
		// raced-with process "owns" it.
		$this->assertSame(
			$session, $this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE )
		);

		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE,
			[ 'username' => $creator->getName() ] + $session );
		$readOnlyMode = $this->getServiceContainer()->getReadOnlyMode();
		$readOnlyMode->setReason( 'Because' );
		$ret = $this->manager->continueAccountCreation( [] );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'readonlytext', $ret->message->getKey() );
		$this->assertSame( [ 'Because' ], $ret->message->getParams() );
		$readOnlyMode->setReason( false );

		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE,
			[ 'username' => $creator->getName() ] + $session );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->continueAccountCreation( [] );
		$this->unhook( 'LocalUserCreated' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'userexists', $ret->message->getKey() );
		$this->assertNull(
			$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE )
		);

		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE,
			[ 'userid' => $creator->getId() ] + $session );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		try {
			$ret = $this->manager->continueAccountCreation( [] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( UnexpectedValueException $ex ) {
			$this->assertEquals( "User \"{$username}\" should exist now, but doesn't!", $ex->getMessage() );
		}
		$this->unhook( 'LocalUserCreated' );
		$this->assertNull(
			$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE )
		);

		$id = $creator->getId();
		$name = $creator->getName();
		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE,
			[ 'username' => $name, 'userid' => $id + 1 ] + $session );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		try {
			$ret = $this->manager->continueAccountCreation( [] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( UnexpectedValueException $ex ) {
			$this->assertEquals(
				"User \"{$name}\" exists, but ID $id !== " . ( $id + 1 ) . '!', $ex->getMessage()
			);
		}
		$this->unhook( 'LocalUserCreated' );
		$this->assertNull(
			$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE )
		);

		$req = $this->getMockBuilder( UserDataAuthenticationRequest::class )
			->onlyMethods( [ 'populateUser' ] )
			->getMock();
		$req->method( 'populateUser' )
			->willReturn( StatusValue::newFatal( 'populatefail' ) );
		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE,
			[ 'reqs' => [ $req ] ] + $session );
		$ret = $this->manager->continueAccountCreation( [] );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'populatefail', $ret->message->getKey() );
		$this->assertNull(
			$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE )
		);
	}

	/**
	 * @dataProvider provideAccountCreation
	 * @param StatusValue $preTest
	 * @param StatusValue $primaryTest
	 * @param StatusValue $secondaryTest
	 * @param array $primaryResponses
	 * @param array $secondaryResponses
	 * @param array $managerResponses
	 */
	public function testAccountCreation(
		StatusValue $preTest, $primaryTest, $secondaryTest,
		array $primaryResponses, array $secondaryResponses, array $managerResponses
	) {
		$creator = $this->getTestSysop()->getUser();
		$username = self::usernameForCreation();

		$this->initializeManager();

		// Set up lots of mocks...
		$req = $this->getMockForAbstractClass( AuthenticationRequest::class );
		$mocks = [];
		foreach ( [ 'pre', 'primary', 'secondary' ] as $key ) {
			$class = ucfirst( $key ) . 'AuthenticationProvider';
			$mocks[$key] = $this->getMockBuilder( "MediaWiki\\Auth\\Abstract$class" )
				->setMockClassName( "MockAbstract$class" )
				->getMock();
			$mocks[$key]->method( 'getUniqueId' )
				->willReturn( $key );
			$mocks[$key]->method( 'testUserForCreation' )
				->willReturn( StatusValue::newGood() );
			$mocks[$key]->method( 'testForAccountCreation' )
				->willReturnCallback(
					function ( $user, $creatorIn, $reqs )
						use ( $username, $creator, $req, $key, $preTest, $primaryTest, $secondaryTest )
					{
						$this->assertSame( $username, $user->getName() );
						$this->assertSame( $creator->getId(), $creatorIn->getId() );
						$this->assertSame( $creator->getName(), $creatorIn->getName() );
						$foundReq = false;
						foreach ( $reqs as $r ) {
							$this->assertSame( $username, $r->username );
							$foundReq = $foundReq || get_class( $r ) === get_class( $req );
						}
						$this->assertTrue( $foundReq, '$reqs contains $req' );
						if ( $key === 'pre' ) {
							return $preTest;
						}
						if ( $key === 'primary' ) {
							return $primaryTest;
						}
						return $secondaryTest;
					}
				);

			for ( $i = 2; $i <= 3; $i++ ) {
				$mocks[$key . $i] = $this->createMock( "MediaWiki\\Auth\\Abstract$class" );
				$mocks[$key . $i]->method( 'getUniqueId' )
					->willReturn( $key . $i );
				$mocks[$key . $i]->method( 'testUserForCreation' )
					->willReturn( StatusValue::newGood() );
				$mocks[$key . $i]->expects( $this->atMost( 1 ) )->method( 'testForAccountCreation' )
					->willReturn( StatusValue::newGood() );
			}
		}

		$mocks['primary']->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mocks['primary']->method( 'testUserExists' )
			->willReturn( false );
		$ct = count( $primaryResponses );
		$callback = $this->returnCallback( function ( $user, $creatorArg, $reqs ) use ( $creator, $username, $req, &$primaryResponses ) {
			$this->assertSame( $username, $user->getName() );
			$this->assertSame( $creator->getName(), $creatorArg->getName() );
			$foundReq = false;
			foreach ( $reqs as $r ) {
				$this->assertSame( $username, $r->username );
				$foundReq = $foundReq || get_class( $r ) === get_class( $req );
			}
			$this->assertTrue( $foundReq, '$reqs contains $req' );
			return array_shift( $primaryResponses );
		} );
		$mocks['primary']->expects( $this->exactly( min( 1, $ct ) ) )
			->method( 'beginPrimaryAccountCreation' )
			->will( $callback );
		$mocks['primary']->expects( $this->exactly( max( 0, $ct - 1 ) ) )
			->method( 'continuePrimaryAccountCreation' )
			->will( $callback );

		$ct = count( $secondaryResponses );
		$callback = $this->returnCallback( function ( $user, $creatorArg, $reqs ) use ( $creator, $username, $req, &$secondaryResponses ) {
			$this->assertSame( $username, $user->getName() );
			$this->assertSame( $creator->getName(), $creatorArg->getName() );
			$foundReq = false;
			foreach ( $reqs as $r ) {
				$this->assertSame( $username, $r->username );
				$foundReq = $foundReq || get_class( $r ) === get_class( $req );
			}
			$this->assertTrue( $foundReq, '$reqs contains $req' );
			return array_shift( $secondaryResponses );
		} );
		$mocks['secondary']->expects( $this->exactly( min( 1, $ct ) ) )
			->method( 'beginSecondaryAccountCreation' )
			->will( $callback );
		$mocks['secondary']->expects( $this->exactly( max( 0, $ct - 1 ) ) )
			->method( 'continueSecondaryAccountCreation' )
			->will( $callback );

		$abstain = AuthenticationResponse::newAbstain();
		$mocks['primary2']->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_LINK );
		$mocks['primary2']->method( 'testUserExists' )
			->willReturn( false );
		$mocks['primary2']->expects( $this->atMost( 1 ) )->method( 'beginPrimaryAccountCreation' )
			->willReturn( $abstain );
		$mocks['primary2']->expects( $this->never() )->method( 'continuePrimaryAccountCreation' );
		$mocks['primary3']->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_NONE );
		$mocks['primary3']->method( 'testUserExists' )
			->willReturn( false );
		$mocks['primary3']->expects( $this->never() )->method( 'beginPrimaryAccountCreation' );
		$mocks['primary3']->expects( $this->never() )->method( 'continuePrimaryAccountCreation' );
		$mocks['secondary2']->expects( $this->atMost( 1 ) )
			->method( 'beginSecondaryAccountCreation' )
			->willReturn( $abstain );
		$mocks['secondary2']->expects( $this->never() )->method( 'continueSecondaryAccountCreation' );
		$mocks['secondary3']->expects( $this->atMost( 1 ) )
			->method( 'beginSecondaryAccountCreation' )
			->willReturn( $abstain );
		$mocks['secondary3']->expects( $this->never() )->method( 'continueSecondaryAccountCreation' );

		$this->preauthMocks = [ $mocks['pre'], $mocks['pre2'] ];
		$this->primaryauthMocks = [ $mocks['primary3'], $mocks['primary'], $mocks['primary2'] ];
		$this->secondaryauthMocks = [
			$mocks['secondary3'], $mocks['secondary'], $mocks['secondary2']
		];

		$this->logger = new TestLogger( true, static function ( $message, $level ) {
			return $level === LogLevel::DEBUG ? null : $message;
		} );
		$expectLog = [];
		$this->initializeManager( true );

		$constraint = Assert::logicalOr(
			$this->equalTo( AuthenticationResponse::PASS ),
			$this->equalTo( AuthenticationResponse::FAIL )
		);
		$providers = array_merge(
			$this->preauthMocks, $this->primaryauthMocks, $this->secondaryauthMocks
		);
		foreach ( $providers as $p ) {
			DynamicPropertyTestHelper::setDynamicProperty( $p, 'postCalled', false );
			$p->expects( $this->atMost( 1 ) )->method( 'postAccountCreation' )
				->willReturnCallback( function ( $user, $creatorArg, $response )
					use ( $creator, $constraint, $p, $username )
				{
					$this->assertInstanceOf( User::class, $user );
					$this->assertSame( $username, $user->getName() );
					$this->assertSame( $creator->getName(), $creatorArg->getName() );
					$this->assertInstanceOf( AuthenticationResponse::class, $response );
					$this->assertThat( $response->status, $constraint );
					DynamicPropertyTestHelper::setDynamicProperty( $p, 'postCalled', $response->status );
				} );
		}

		// We're testing with $wgNewUserLog = false, so assert that it worked
		$dbw = $this->getDb();
		$maxLogId = $dbw->newSelectQueryBuilder()
			->select( 'MAX(log_id)' )
			->from( 'logging' )
			->where( [ 'log_type' => 'newusers' ] )
			->fetchField();

		$first = true;
		$created = false;
		foreach ( $managerResponses as $i => $response ) {
			$success = $response instanceof AuthenticationResponse &&
				$response->status === AuthenticationResponse::PASS;
			if ( $i === 'created' ) {
				$created = true;
				$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->once() )
					->with(
						$this->callback( static function ( $user ) use ( $username ) {
							return $user->getName() === $username;
						} ),
						false
					);
				$expectLog[] = [ LogLevel::INFO, "Creating user {user} during account creation" ];
			} else {
				$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
			}

			try {
				if ( $first ) {
					$userReq = new UsernameAuthenticationRequest;
					$userReq->username = $username;
					$ret = $this->manager->beginAccountCreation(
						$creator, [ $userReq, $req ], 'http://localhost/'
					);
				} else {
					$ret = $this->manager->continueAccountCreation( [ $req ] );
				}
				if ( $response instanceof Exception ) {
					$this->fail( 'Expected exception not thrown', "Response $i" );
				}
			} catch ( Exception $ex ) {
				if ( !$response instanceof Exception ) {
					throw $ex;
				}
				$this->assertEquals( $response->getMessage(), $ex->getMessage(), "Response $i, exception" );
				$this->assertNull(
					$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE ),
					"Response $i, exception, session state"
				);
				$this->unhook( 'LocalUserCreated' );
				return;
			}

			$this->unhook( 'LocalUserCreated' );

			$this->assertSame( 'http://localhost/', $req->returnToUrl );

			if ( $success ) {
				$this->assertNotNull( $ret->loginRequest, "Response $i, login marker" );
				$this->assertContains(
					$ret->loginRequest, $this->managerPriv->createdAccountAuthenticationRequests,
					"Response $i, login marker"
				);

				$expectLog[] = [
					LogLevel::INFO,
					"MediaWiki\Auth\AuthManager::continueAccountCreation: Account creation succeeded for {user}"
				];

				// Set some fields in the expected $response that we couldn't
				// know in provideAccountCreation().
				$response->username = $username;
				$response->loginRequest = $ret->loginRequest;
			} else {
				$this->assertNull( $ret->loginRequest, "Response $i, login marker" );
				$this->assertSame( [], $this->managerPriv->createdAccountAuthenticationRequests,
					"Response $i, login marker" );
			}
			$ret->message = $this->message( $ret->message );
			$this->assertResponseEquals( $response, $ret, "Response $i, response" );
			if ( $success || $response->status === AuthenticationResponse::FAIL ) {
				$this->assertNull(
					$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE ),
					"Response $i, session state"
				);
				foreach ( $providers as $p ) {
					$this->assertSame( $response->status, DynamicPropertyTestHelper::getDynamicProperty( $p, 'postCalled' ),
						"Response $i, post-auth callback called" );
				}
			} else {
				$this->assertNotNull(
					$this->request->getSession()->getSecret( AuthManager::ACCOUNT_CREATION_STATE ),
					"Response $i, session state"
				);
				foreach ( $ret->neededRequests as $neededReq ) {
					$this->assertEquals( AuthManager::ACTION_CREATE, $neededReq->action,
						"Response $i, neededRequest action" );
				}
				$this->assertEquals(
					$ret->neededRequests,
					$this->manager->getAuthenticationRequests( AuthManager::ACTION_CREATE_CONTINUE ),
					"Response $i, continuation check"
				);
				foreach ( $providers as $p ) {
					$this->assertFalse( DynamicPropertyTestHelper::getDynamicProperty( $p, 'postCalled' ), "Response $i, post-auth callback not called" );
				}
			}

			$userIdentity = $this->userIdentityLookup->getUserIdentityByName( $username );
			$this->assertSame( $created, $userIdentity && $userIdentity->isRegistered() );

			$first = false;
		}

		$this->assertSame( $expectLog, $this->logger->getBuffer() );

		$this->assertSame(
			$maxLogId,
			$dbw->newSelectQueryBuilder()
				->select( 'MAX(log_id)' )
				->from( 'logging' )
				->where( [ 'log_type' => 'newusers' ] )
				->fetchField() );
	}

	public function provideAccountCreation() {
		$req = $this->getMockForAbstractClass( AuthenticationRequest::class );
		$good = StatusValue::newGood();

		return [
			'Pre-creation test fail in pre' => [
				StatusValue::newFatal( 'fail-from-pre' ), $good, $good,
				[],
				[],
				[
					AuthenticationResponse::newFail( $this->message( 'fail-from-pre' ) ),
				]
			],
			'Pre-creation test fail in primary' => [
				$good, StatusValue::newFatal( 'fail-from-primary' ), $good,
				[],
				[],
				[
					AuthenticationResponse::newFail( $this->message( 'fail-from-primary' ) ),
				]
			],
			'Pre-creation test fail in secondary' => [
				$good, $good, StatusValue::newFatal( 'fail-from-secondary' ),
				[],
				[],
				[
					AuthenticationResponse::newFail( $this->message( 'fail-from-secondary' ) ),
				]
			],
			'Failure in primary' => [
				$good, $good, $good,
				$tmp = [
					AuthenticationResponse::newFail( $this->message( 'fail-from-primary' ) ),
				],
				[],
				$tmp
			],
			'All primary abstain' => [
				$good, $good, $good,
				[
					AuthenticationResponse::newAbstain(),
				],
				[],
				[
					AuthenticationResponse::newFail( $this->message( 'authmanager-create-no-primary' ) )
				]
			],
			'Primary UI, then redirect, then fail' => [
				$good, $good, $good,
				$tmp = [
					AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ),
					AuthenticationResponse::newRedirect( [ $req ], '/foo.html', [ 'foo' => 'bar' ] ),
					AuthenticationResponse::newFail( $this->message( 'fail-in-primary-continue' ) ),
				],
				[],
				$tmp
			],
			'Primary redirect, then abstain' => [
				$good, $good, $good,
				[
					$tmp = AuthenticationResponse::newRedirect(
						[ $req ], '/foo.html', [ 'foo' => 'bar' ]
					),
					AuthenticationResponse::newAbstain(),
				],
				[],
				[
					$tmp,
					new DomainException(
						'MockAbstractPrimaryAuthenticationProvider::continuePrimaryAccountCreation() returned ABSTAIN'
					)
				]
			],
			'Primary UI, then pass; secondary abstain' => [
				$good, $good, $good,
				[
					$tmp1 = AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ),
					AuthenticationResponse::newPass(),
				],
				[
					AuthenticationResponse::newAbstain(),
				],
				[
					$tmp1,
					'created' => AuthenticationResponse::newPass( '' ),
				]
			],
			'Primary pass; secondary UI then pass' => [
				$good, $good, $good,
				[
					AuthenticationResponse::newPass( '' ),
				],
				[
					$tmp1 = AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ),
					AuthenticationResponse::newPass( '' ),
				],
				[
					'created' => $tmp1,
					AuthenticationResponse::newPass( '' ),
				]
			],
			'Primary pass; secondary fail' => [
				$good, $good, $good,
				[
					AuthenticationResponse::newPass(),
				],
				[
					AuthenticationResponse::newFail( $this->message( '...' ) ),
				],
				[
					'created' => new DomainException(
						'MockAbstractSecondaryAuthenticationProvider::beginSecondaryAccountCreation() returned FAIL. ' .
							'Secondary providers are not allowed to fail account creation, ' .
							'that should have been done via testForAccountCreation().'
					)
				]
			],
		];
	}

	/**
	 * @dataProvider provideAccountCreationLogging
	 * @param bool $isAnon
	 * @param string|null $logSubtype
	 */
	public function testAccountCreationLogging( $isAnon, $logSubtype ) {
		$creator = $isAnon ? new User : $this->getTestSysop()->getUser();
		$username = self::usernameForCreation();

		$this->initializeManager();

		// Set up lots of mocks...
		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )
			->willReturn( 'primary' );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newGood() );
		$mock->method( 'testForAccountCreation' )
			->willReturn( StatusValue::newGood() );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )
			->willReturn( false );
		$mock->method( 'beginPrimaryAccountCreation' )
			->willReturn( AuthenticationResponse::newPass( $username ) );
		$mock->method( 'finishAccountCreation' )
			->willReturn( $logSubtype );

		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );
		$this->logger->setCollect( true );

		$this->config->set( MainConfigNames::NewUserLog, true );

		$dbw = $this->getDb();
		$maxLogId = $dbw->newSelectQueryBuilder()
			->select( 'MAX(log_id)' )
			->from( 'logging' )
			->where( [ 'log_type' => 'newusers' ] )
			->fetchField();

		$userReq = new UsernameAuthenticationRequest;
		$userReq->username = $username;
		$reasonReq = new CreationReasonAuthenticationRequest;
		$reasonReq->reason = $this->toString();
		$ret = $this->manager->beginAccountCreation(
			$creator, [ $userReq, $reasonReq ], 'http://localhost/'
		);

		$this->assertSame( AuthenticationResponse::PASS, $ret->status );

		$user = User::newFromName( $username );
		$this->assertNotEquals( 0, $user->getId() );
		$this->assertNotEquals( $creator->getId(), $user->getId() );

		$queryBuilder = DatabaseLogEntry::newSelectQueryBuilder( $dbw )
			->where( [ 'log_id > ' . (int)$maxLogId, 'log_type' => 'newusers' ] );
		$rows = iterator_to_array( $queryBuilder->caller( __METHOD__ )->fetchResultSet() );
		$this->assertCount( 1, $rows );
		$entry = DatabaseLogEntry::newFromRow( reset( $rows ) );

		$this->assertSame( $logSubtype ?: ( $isAnon ? 'create' : 'create2' ), $entry->getSubtype() );
		$this->assertSame(
			$isAnon ? $user->getId() : $creator->getId(),
			$entry->getPerformerIdentity()->getId()
		);
		$this->assertSame(
			$isAnon ? $user->getName() : $creator->getName(),
			$entry->getPerformerIdentity()->getName()
		);
		$this->assertSame( $user->getUserPage()->getFullText(), $entry->getTarget()->getFullText() );
		$this->assertSame( [ '4::userid' => $user->getId() ], $entry->getParameters() );
		$this->assertSame( $this->toString(), $entry->getComment() );
	}

	public static function provideAccountCreationLogging() {
		return [
			[ true, null ],
			[ true, 'foobar' ],
			[ false, null ],
			[ false, 'byemail' ],
		];
	}

	public function testAutoAccountCreation() {
		// PHPUnit seems to have a bug where it will call the ->with()
		// callbacks for our hooks again after the test is run (WTF?), which
		// breaks here because $username no longer matches $user by the end of
		// the testing.
		$workaroundPHPUnitBug = false;

		$username = self::usernameForCreation();
		$expectedSource = AuthManager::AUTOCREATE_SOURCE_SESSION;

		$this->setGroupPermissions( '*', 'createaccount', true );
		$this->setGroupPermissions( '*', 'autocreateaccount', false );
		$this->initializeManager( true );

		// Set up lots of mocks...
		$mocks = [];
		foreach ( [ 'pre', 'primary', 'secondary' ] as $key ) {
			$class = ucfirst( $key ) . 'AuthenticationProvider';
			$mocks[$key] = $this->createMock( "MediaWiki\\Auth\\Abstract$class" );
			$mocks[$key]->method( 'getUniqueId' )
				->willReturn( $key );
		}

		$good = StatusValue::newGood();
		$ok = StatusValue::newFatal( 'ok' );
		$callback = $this->callback( static function ( $user ) use ( &$username, &$workaroundPHPUnitBug ) {
			return $workaroundPHPUnitBug || $user->getName() === $username;
		} );
		$callback2 = $this->callback(
			static function ( $source ) use ( &$expectedSource, &$workaroundPHPUnitBug ) {
				return $workaroundPHPUnitBug || $source === $expectedSource;
			}
		);

		$mocks['pre']->expects( $this->exactly( 13 ) )->method( 'testUserForCreation' )
			->with( $callback, $callback2 )
			->willReturnOnConsecutiveCalls(
				$ok, $ok, $ok, // For testing permissions
				StatusValue::newFatal( 'fail-in-pre' ), $good, $good,
				$good, // backoff test
				$good, // addToDatabase fails test
				$good, // addToDatabase throws test
				$good, // addToDatabase exists test
				$good, $good, $good // success
			);

		$mocks['primary']->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mocks['primary']->method( 'testUserExists' )
			->willReturn( true );
		$mocks['primary']->expects( $this->exactly( 9 ) )->method( 'testUserForCreation' )
			->with( $callback, $callback2 )
			->willReturnOnConsecutiveCalls(
				StatusValue::newFatal( 'fail-in-primary' ), $good,
				$good, // backoff test
				$good, // addToDatabase fails test
				$good, // addToDatabase throws test
				$good, // addToDatabase exists test
				$good, $good, $good
			);
		$mocks['primary']->expects( $this->exactly( 3 ) )->method( 'autoCreatedAccount' )
			->with( $callback, $callback2 );

		$mocks['secondary']->expects( $this->exactly( 8 ) )->method( 'testUserForCreation' )
			->with( $callback, $callback2 )
			->willReturnOnConsecutiveCalls(
				StatusValue::newFatal( 'fail-in-secondary' ),
				$good, // backoff test
				$good, // addToDatabase fails test
				$good, // addToDatabase throws test
				$good, // addToDatabase exists test
				$good, $good, $good
			);
		$mocks['secondary']->expects( $this->exactly( 3 ) )->method( 'autoCreatedAccount' )
			->with( $callback, $callback2 );

		$this->preauthMocks = [ $mocks['pre'] ];
		$this->primaryauthMocks = [ $mocks['primary'] ];
		$this->secondaryauthMocks = [ $mocks['secondary'] ];
		$this->initializeManager( true );
		$session = $this->request->getSession();

		$logger = new TestLogger( true, static function ( $m ) {
			$m = str_replace( 'MediaWiki\\Auth\\AuthManager::autoCreateUser: ', '', $m );
			return $m;
		} );
		$this->logger = $logger;
		$this->manager->setLogger( $logger );

		try {
			$userMock = $this->createMock( User::class );
			$this->manager->autoCreateUser( $userMock, 'InvalidSource', true, true );
			$this->fail( 'Expected exception not thrown' );
		} catch ( InvalidArgumentException $ex ) {
			$this->assertSame( 'Unknown auto-creation source: InvalidSource', $ex->getMessage() );
		}

		// First, check an existing user
		$session->clear();
		$existingUser = $this->getTestSysop()->getUser();
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $existingUser, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$expect = Status::newGood();
		$expect->warning( 'userexists' );
		$this->assertEquals( $expect, $ret );
		$this->assertNotEquals( 0, $existingUser->getId() );
		$this->assertEquals( $existingUser->getId(), $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, '{username} already exists locally' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();

		$session->clear();
		$user = $this->getTestSysop()->getUser();
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, false, true );
		$this->unhook( 'LocalUserCreated' );
		$expect = Status::newGood();
		$expect->warning( 'userexists' );
		$this->assertEquals( $expect, $ret );
		$this->assertNotEquals( 0, $user->getId() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, '{username} already exists locally' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();

		// Wiki is read-only
		$session->clear();
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$readOnlyMode = $this->getServiceContainer()->getReadOnlyMode();
		$readOnlyMode->setReason( 'Because' );
		$user = User::newFromName( $username );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertEquals( Status::newFatal( wfMessage( 'readonlytext', 'Because' ) ), $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, 'denied because of read only mode: {reason}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$readOnlyMode->setReason( false );

		// Session blacklisted
		$session->clear();
		$session->set( AuthManager::AUTOCREATE_BLOCKLIST, 'test' );
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertEquals( Status::newFatal( 'test' ), $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, 'blacklisted in session {sessionid}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();

		$session->clear();
		$session->set( AuthManager::AUTOCREATE_BLOCKLIST, StatusValue::newFatal( 'test2' ) );
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertEquals( Status::newFatal( 'test2' ), $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, 'blacklisted in session {sessionid}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();

		// Invalid name
		$session->clear();
		$user = User::newFromName( $username . "\u{0080}", false );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertEquals( Status::newFatal( 'noname' ), $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username . "\u{0080}", $user->getId() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, 'name "{username}" is not usable' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertSame( 'noname', $session->get( AuthManager::AUTOCREATE_BLOCKLIST ) );

		// IP unable to create accounts
		$this->setGroupPermissions( '*', 'createaccount', false );
		$this->setGroupPermissions( '*', 'autocreateaccount', false );
		$this->initializeManager( true );
		$session = $this->request->getSession();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertTrue( $ret->hasMessage( 'badaccess-group0' ) );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, 'cannot create or autocreate accounts' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertEquals(
			(string)$ret, (string)$session->get( AuthManager::AUTOCREATE_BLOCKLIST )
		);

		// maintenance scripts always work
		$expectedSource = AuthManager::AUTOCREATE_SOURCE_MAINT;
		$this->setGroupPermissions( '*', 'createaccount', false );
		$this->setGroupPermissions( '*', 'autocreateaccount', false );
		$this->initializeManager( true );
		$session = $this->request->getSession();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_MAINT, true, false );
		$this->unhook( 'LocalUserCreated' );
		$this->assertStatusError( 'ok', $ret );

		// Test that both permutations of permissions are allowed
		// (this hits the two "ok" entries in $mocks['pre'])
		$expectedSource = AuthManager::AUTOCREATE_SOURCE_SESSION;
		$this->setGroupPermissions( '*', 'createaccount', false );
		$this->setGroupPermissions( '*', 'autocreateaccount', true );
		$this->initializeManager( true );
		$session = $this->request->getSession();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertStatusError( 'ok', $ret );

		$this->setGroupPermissions( '*', 'createaccount', true );
		$this->setGroupPermissions( '*', 'autocreateaccount', false );
		$this->initializeManager( true );
		$session = $this->request->getSession();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertStatusError( 'ok', $ret );
		$logger->clearBuffer();

		// Test lock fail
		$session->clear();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$cache = $this->objectCacheFactory->getLocalClusterInstance();
		$lock = $cache->getScopedLock( $cache->makeGlobalKey( 'account', md5( $username ) ) );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		unset( $lock );
		$this->unhook( 'LocalUserCreated' );
		$this->assertStatusError( 'usernameinprogress', $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, 'Could not acquire account creation lock' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();

		// Test pre-authentication provider fail
		$session->clear();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertStatusError( 'fail-in-pre', $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, 'Provider denied creation of {username}: {reason}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertEquals(
			StatusValue::newFatal( 'fail-in-pre' ), $session->get( AuthManager::AUTOCREATE_BLOCKLIST )
		);

		$session->clear();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertStatusError( 'fail-in-primary', $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, 'Provider denied creation of {username}: {reason}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertEquals(
			StatusValue::newFatal( 'fail-in-primary' ), $session->get( AuthManager::AUTOCREATE_BLOCKLIST )
		);

		$session->clear();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertStatusError( 'fail-in-secondary', $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, 'Provider denied creation of {username}: {reason}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertEquals(
			StatusValue::newFatal( 'fail-in-secondary' ), $session->get( AuthManager::AUTOCREATE_BLOCKLIST )
		);

		// Test backoff
		$cache = $this->objectCacheFactory->getLocalClusterInstance();
		$backoffKey = $cache->makeKey( 'AuthManager', 'autocreate-failed', md5( $username ) );
		$cache->set( $backoffKey, true );
		$session->clear();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertStatusError( 'authmanager-autocreate-exception', $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::DEBUG, '{username} denied by prior creation attempt failures' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertSame( null, $session->get( AuthManager::AUTOCREATE_BLOCKLIST ) );
		$cache->delete( $backoffKey );

		// Test addToDatabase fails
		$session->clear();
		$user = $this->getMockBuilder( User::class )
			->onlyMethods( [ 'addToDatabase' ] )->getMock();
		$user->expects( $this->once() )->method( 'addToDatabase' )
			->willReturn( Status::newFatal( 'because' ) );
		$user->setName( $username );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->assertStatusError( 'because', $ret );
		$this->assertSame( 0, $user->getId() );
		$this->assertNotEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::INFO, 'creating new user ({username}) - from: {from}' ],
			[ LogLevel::ERROR, '{username} failed with message {msg}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertSame( null, $session->get( AuthManager::AUTOCREATE_BLOCKLIST ) );

		// Test addToDatabase throws an exception
		$cache = $this->objectCacheFactory->getLocalClusterInstance();
		$backoffKey = $cache->makeKey( 'AuthManager', 'autocreate-failed', md5( $username ) );
		$this->assertFalse( $cache->get( $backoffKey ) );
		$session->clear();
		$user = $this->getMockBuilder( User::class )
			->onlyMethods( [ 'addToDatabase' ] )->getMock();
		$user->expects( $this->once() )->method( 'addToDatabase' )
			->willThrowException( new Exception( 'Excepted' ) );
		$user->setName( $username );
		try {
			$this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
			$this->fail( 'Expected exception not thrown' );
		} catch ( Exception $ex ) {
			$this->assertSame( 'Excepted', $ex->getMessage() );
		}
		$this->assertSame( 0, $user->getId() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::INFO, 'creating new user ({username}) - from: {from}' ],
			[ LogLevel::ERROR, '{username} failed with exception {exception}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertSame( null, $session->get( AuthManager::AUTOCREATE_BLOCKLIST ) );
		$this->assertNotFalse( $cache->get( $backoffKey ) );
		$cache->delete( $backoffKey );

		// Test addToDatabase fails because the user already exists.
		$session->clear();
		$user = $this->getMockBuilder( User::class )
			->onlyMethods( [ 'addToDatabase' ] )->getMock();
		$user->expects( $this->once() )->method( 'addToDatabase' )
			->willReturnCallback( function () use ( $username, &$user ) {
				$oldUser = User::newFromName( $username );
				$status = $oldUser->addToDatabase();
				$this->assertStatusOK( $status );
				$user->setId( $oldUser->getId() );
				return Status::newFatal( 'userexists' );
			} );
		$user->setName( $username );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$expect = Status::newGood();
		$expect->warning( 'userexists' );
		$this->assertEquals( $expect, $ret );
		$this->assertNotEquals( 0, $user->getId() );
		$this->assertEquals( $username, $user->getName() );
		$this->assertEquals( $user->getId(), $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::INFO, 'creating new user ({username}) - from: {from}' ],
			[ LogLevel::INFO, '{username} already exists locally (race)' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertSame( null, $session->get( AuthManager::AUTOCREATE_BLOCKLIST ) );

		// Success!
		$session->clear();
		$username = self::usernameForCreation();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->once() )
			->with( $callback, true );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertEquals( Status::newGood(), $ret );
		$this->assertNotEquals( 0, $user->getId() );
		$this->assertEquals( $username, $user->getName() );
		$this->assertEquals( $user->getId(), $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::INFO, 'creating new user ({username}) - from: {from}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();

		$dbw = $this->getDb();
		$maxLogId = $dbw->newSelectQueryBuilder()
			->select( 'MAX(log_id)' )
			->from( 'logging' )
			->where( [ 'log_type' => 'newusers' ] )
			->fetchField();
		$session->clear();
		$username = self::usernameForCreation();
		$user = User::newFromName( $username );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->once() )
			->with( $callback, true );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, false, true );
		$this->unhook( 'LocalUserCreated' );
		$this->assertEquals( Status::newGood(), $ret );
		$this->assertNotEquals( 0, $user->getId() );
		$this->assertEquals( $username, $user->getName() );
		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( [
			[ LogLevel::INFO, 'creating new user ({username}) - from: {from}' ],
		], $logger->getBuffer() );
		$logger->clearBuffer();
		$this->assertSame(
			$maxLogId,
			$dbw->newSelectQueryBuilder()
				->select( 'MAX(log_id)' )
				->from( 'logging' )
				->where( [ 'log_type' => 'newusers' ] )
				->fetchField() );

		$this->config->set( MainConfigNames::NewUserLog, true );
		$session->clear();
		$username = self::usernameForCreation();
		$user = User::newFromName( $username );
		$ret = $this->manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, false, true );
		$this->assertEquals( Status::newGood(), $ret );
		$logger->clearBuffer();

		$queryBuilder = DatabaseLogEntry::newSelectQueryBuilder( $dbw )
			->where( [ 'log_id > ' . (int)$maxLogId, 'log_type' => 'newusers' ] );
		$rows = iterator_to_array( $queryBuilder->caller( __METHOD__ )->fetchResultSet() );
		$this->assertCount( 1, $rows );
		$entry = DatabaseLogEntry::newFromRow( reset( $rows ) );

		$this->assertSame( 'autocreate', $entry->getSubtype() );
		$this->assertSame( $user->getId(), $entry->getPerformerIdentity()->getId() );
		$this->assertSame( $user->getName(), $entry->getPerformerIdentity()->getName() );
		$this->assertSame( $user->getUserPage()->getFullText(), $entry->getTarget()->getFullText() );
		$this->assertSame( [ '4::userid' => $user->getId() ], $entry->getParameters() );

		$workaroundPHPUnitBug = true;
	}

	/**
	 * @dataProvider provideAutoCreateUserBlocks
	 */
	public function testAutoCreateUserBlocks(
		string $blockType,
		array $blockOptions,
		string $performerType,
		bool $expectedStatus

	) {
		if ( $blockType === 'ip' ) {
			$blockOptions['address'] = '127.0.0.0/24';
		} elseif ( $blockType === 'global-ip' ) {
			$this->setTemporaryHook( 'GetUserBlock',
				static function ( $user, $ip, &$block ) use ( $blockOptions ) {
					$block = new SystemBlock( $blockOptions );
					$block->isCreateAccountBlocked( true );
				}
			);
			$blockOptions = null;
		} elseif ( $blockType === 'none' ) {
			$blockOptions = null;
		} else {
			$this->fail( "Unknown block type \"$blockType\"" );
		}

		if ( $blockOptions !== null ) {
			$blockOptions += [
				'by' => $this->getTestSysop()->getUser(),
				'reason' => __METHOD__,
				'expiry' => time() + 100500,
			];
			$blockStore = $this->getServiceContainer()->getDatabaseBlockStore();
			$block = new DatabaseBlock( $blockOptions );
			$blockStore->insertBlock( $block );
		}

		if ( $performerType === 'sysop' ) {
			$performer = $this->getTestSysop()->getUser();
		} elseif ( $performerType === 'anon' ) {
			$performer = null;
		} else {
			$this->fail( "Unknown performer type \"$performerType\"" );
		}

		$this->logger = LoggerFactory::getInstance( 'AuthManagerTest' );
		$this->initializeManager( true );

		$user = $this->userFactory->newFromName( 'NewUser' );
		$status = $this->manager->autoCreateUser( $user,
			AuthManager::AUTOCREATE_SOURCE_SESSION, true, true, $performer );
		$this->assertSame( $expectedStatus, $status->isGood() );
	}

	public static function provideAutoCreateUserBlocks() {
		return [
			// block type (ip/global/none), block options, performer, expected status
			'not blocked' => [ 'none', [], 'anon', true ],
			'ip-blocked' => [ 'ip', [], 'anon', true ],
			'ip-blocked with createAccount' => [
				'ip',
				[ 'createAccount' => true ],
				'anon',
				false
			],
			'partially ip-blocked' => [
				'ip',
				[ 'restrictions' => [ new PageRestriction( 0, 1 ) ] ],
				'anon',
				true
			],
			'ip-blocked with sysop performer' => [
				'ip',
				[ 'createAccount' => true ],
				'sysop',
				true
			],
			'globally blocked' => [ 'global-ip', [], 'anon', false ],
		];
	}

	/**
	 * @dataProvider provideGetAuthenticationRequests
	 * @param string $action
	 * @param array $expect
	 * @param array $state
	 */
	public function testGetAuthenticationRequests( $action, $expect, $state = [] ) {
		$makeReq = function ( $key ) use ( $action ) {
			$req = $this->createMock( AuthenticationRequest::class );
			$req->method( 'getUniqueId' )
				->willReturn( $key );
			$req->action = $action === AuthManager::ACTION_UNLINK ? AuthManager::ACTION_REMOVE : $action;
			return $req;
		};
		$cmpReqs = static function ( $a, $b ) {
			$ret = strcmp( get_class( $a ), get_class( $b ) );
			if ( !$ret ) {
				$ret = strcmp( $a->getUniqueId(), $b->getUniqueId() );
			}
			return $ret;
		};

		$good = StatusValue::newGood();

		$mocks = [];
		$mocks['pre'] = $this->createMock( AbstractPreAuthenticationProvider::class );
		$mocks['pre']->method( 'getUniqueId' )
			->willReturn( 'pre' );
		$mocks['pre']->method( 'getAuthenticationRequests' )
			->willReturnCallback( static function ( $action ) use ( $makeReq ) {
				return [ $makeReq( "pre-$action" ), $makeReq( 'generic' ) ];
			} );
		foreach ( [ 'primary', 'secondary' ] as $key ) {
			$class = ucfirst( $key ) . 'AuthenticationProvider';
			$mocks[$key] = $this->createMock( "MediaWiki\\Auth\\Abstract$class" );
			$mocks[$key]->method( 'getUniqueId' )
				->willReturn( $key );
			$mocks[$key]->method( 'getAuthenticationRequests' )
				->willReturnCallback( static function ( $action ) use ( $key, $makeReq ) {
					return [ $makeReq( "$key-$action" ), $makeReq( 'generic' ) ];
				} );
			$mocks[$key]->method( 'providerAllowsAuthenticationDataChange' )
				->willReturn( $good );
		}

		foreach ( [
			PrimaryAuthenticationProvider::TYPE_NONE,
			PrimaryAuthenticationProvider::TYPE_CREATE,
			PrimaryAuthenticationProvider::TYPE_LINK
		] as $type ) {
			$class = 'PrimaryAuthenticationProvider';
			$mocks["primary-$type"] = $this->createMock( "MediaWiki\\Auth\\Abstract$class" );
			$mocks["primary-$type"]->method( 'getUniqueId' )
				->willReturn( "primary-$type" );
			$mocks["primary-$type"]->method( 'accountCreationType' )
				->willReturn( $type );
			$mocks["primary-$type"]->method( 'getAuthenticationRequests' )
				->willReturnCallback( static function ( $action ) use ( $type, $makeReq ) {
					return [ $makeReq( "primary-$type-$action" ), $makeReq( 'generic' ) ];
				} );
			$mocks["primary-$type"]->method( 'providerAllowsAuthenticationDataChange' )
				->willReturn( $good );
			$this->primaryauthMocks[] = $mocks["primary-$type"];
		}

		$mocks['primary2'] = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mocks['primary2']->method( 'getUniqueId' )
			->willReturn( 'primary2' );
		$mocks['primary2']->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_LINK );
		$mocks['primary2']->method( 'getAuthenticationRequests' )
			->willReturn( [] );
		$mocks['primary2']->method( 'providerAllowsAuthenticationDataChange' )
			->willReturnCallback( static function ( $req ) use ( $good ) {
				return $req->getUniqueId() === 'generic' ? StatusValue::newFatal( 'no' ) : $good;
			} );
		$this->primaryauthMocks[] = $mocks['primary2'];

		$this->preauthMocks = [ $mocks['pre'] ];
		$this->secondaryauthMocks = [ $mocks['secondary'] ];
		$this->initializeManager( true );

		if ( $state ) {
			if ( isset( $state['continueRequests'] ) ) {
				$state['continueRequests'] = array_map( $makeReq, $state['continueRequests'] );
			}
			if ( $action === AuthManager::ACTION_LOGIN_CONTINUE ) {
				$this->request->getSession()->setSecret( AuthManager::AUTHN_STATE, $state );
			} elseif ( $action === AuthManager::ACTION_CREATE_CONTINUE ) {
				$this->request->getSession()->setSecret( AuthManager::ACCOUNT_CREATION_STATE, $state );
			} elseif ( $action === AuthManager::ACTION_LINK_CONTINUE ) {
				$this->request->getSession()->setSecret( AuthManager::ACCOUNT_LINK_STATE, $state );
			}
		}

		$expectReqs = array_map( $makeReq, $expect );
		if ( $action === AuthManager::ACTION_LOGIN ) {
			$req = new RememberMeAuthenticationRequest;
			$req->action = $action;
			$req->required = AuthenticationRequest::REQUIRED;
			$expectReqs[] = $req;
		} elseif ( $action === AuthManager::ACTION_CREATE ) {
			$req = new UsernameAuthenticationRequest;
			$req->action = $action;
			$expectReqs[] = $req;
			$req = new UserDataAuthenticationRequest;
			$req->action = $action;
			$req->required = AuthenticationRequest::REQUIRED;
			$expectReqs[] = $req;
		}
		usort( $expectReqs, $cmpReqs );

		$actual = $this->manager->getAuthenticationRequests( $action );
		foreach ( $actual as $req ) {
			// Don't test this here.
			$req->required = AuthenticationRequest::REQUIRED;
		}
		usort( $actual, $cmpReqs );

		$this->assertEquals( $expectReqs, $actual );

		// Test CreationReasonAuthenticationRequest gets returned
		if ( $action === AuthManager::ACTION_CREATE ) {
			$req = new CreationReasonAuthenticationRequest;
			$req->action = $action;
			$req->required = AuthenticationRequest::REQUIRED;
			$expectReqs[] = $req;
			usort( $expectReqs, $cmpReqs );

			$user = $this->getTestSysop()->getUser();
			$actual = $this->manager->getAuthenticationRequests( $action, $user );
			foreach ( $actual as $req ) {
				// Don't test this here.
				$req->required = AuthenticationRequest::REQUIRED;
			}
			usort( $actual, $cmpReqs );

			$this->assertEquals( $expectReqs, $actual );
		}
	}

	public static function provideGetAuthenticationRequests() {
		return [
			[
				AuthManager::ACTION_LOGIN,
				[ 'pre-login', 'primary-none-login', 'primary-create-login',
					'primary-link-login', 'secondary-login', 'generic' ],
			],
			[
				AuthManager::ACTION_CREATE,
				[ 'pre-create', 'primary-none-create', 'primary-create-create',
					'primary-link-create', 'secondary-create', 'generic' ],
			],
			[
				AuthManager::ACTION_LINK,
				[ 'primary-link-link', 'generic' ],
			],
			[
				AuthManager::ACTION_CHANGE,
				[ 'primary-none-change', 'primary-create-change', 'primary-link-change',
					'secondary-change' ],
			],
			[
				AuthManager::ACTION_REMOVE,
				[ 'primary-none-remove', 'primary-create-remove', 'primary-link-remove',
					'secondary-remove' ],
			],
			[
				AuthManager::ACTION_UNLINK,
				[ 'primary-link-remove' ],
			],
			[
				AuthManager::ACTION_LOGIN_CONTINUE,
				[],
			],
			[
				AuthManager::ACTION_LOGIN_CONTINUE,
				$reqs = [ 'continue-login', 'foo', 'bar' ],
				[
					'continueRequests' => $reqs,
				],
			],
			[
				AuthManager::ACTION_CREATE_CONTINUE,
				[],
			],
			[
				AuthManager::ACTION_CREATE_CONTINUE,
				$reqs = [ 'continue-create', 'foo', 'bar' ],
				[
					'continueRequests' => $reqs,
				],
			],
			[
				AuthManager::ACTION_LINK_CONTINUE,
				[],
			],
			[
				AuthManager::ACTION_LINK_CONTINUE,
				$reqs = [ 'continue-link', 'foo', 'bar' ],
				[
					'continueRequests' => $reqs,
				],
			],
		];
	}

	public function testGetAuthenticationRequestsRequired() {
		$makeReq = function ( $key, $required ) {
			$req = $this->createMock( AuthenticationRequest::class );
			$req->method( 'getUniqueId' )
				->willReturn( $key );
			$req->action = AuthManager::ACTION_LOGIN;
			$req->required = $required;
			return $req;
		};
		$cmpReqs = static function ( $a, $b ) {
			$ret = strcmp( get_class( $a ), get_class( $b ) );
			if ( !$ret ) {
				$ret = strcmp( $a->getUniqueId(), $b->getUniqueId() );
			}
			return $ret;
		};

		$primary1 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$primary1->method( 'getUniqueId' )
			->willReturn( 'primary1' );
		$primary1->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$primary1->method( 'getAuthenticationRequests' )
			->willReturnCallback( static function ( $action ) use ( $makeReq ) {
				return [
					$makeReq( "primary-shared", AuthenticationRequest::REQUIRED ),
					$makeReq( "required", AuthenticationRequest::REQUIRED ),
					$makeReq( "optional", AuthenticationRequest::OPTIONAL ),
					$makeReq( "foo", AuthenticationRequest::REQUIRED ),
					$makeReq( "bar", AuthenticationRequest::REQUIRED ),
					$makeReq( "baz", AuthenticationRequest::OPTIONAL ),
				];
			} );

		$primary2 = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$primary2->method( 'getUniqueId' )
			->willReturn( 'primary2' );
		$primary2->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$primary2->method( 'getAuthenticationRequests' )
			->willReturnCallback( static function ( $action ) use ( $makeReq ) {
				return [
					$makeReq( "primary-shared", AuthenticationRequest::REQUIRED ),
					$makeReq( "required2", AuthenticationRequest::REQUIRED ),
					$makeReq( "optional2", AuthenticationRequest::OPTIONAL ),
				];
			} );

		$secondary = $this->createMock( AbstractSecondaryAuthenticationProvider::class );
		$secondary->method( 'getUniqueId' )
			->willReturn( 'secondary' );
		$secondary->method( 'getAuthenticationRequests' )
			->willReturnCallback( static function ( $action ) use ( $makeReq ) {
				return [
					$makeReq( "foo", AuthenticationRequest::OPTIONAL ),
					$makeReq( "bar", AuthenticationRequest::REQUIRED ),
					$makeReq( "baz", AuthenticationRequest::REQUIRED ),
				];
			} );

		$rememberReq = new RememberMeAuthenticationRequest;
		$rememberReq->action = AuthManager::ACTION_LOGIN;

		$this->primaryauthMocks = [ $primary1, $primary2 ];
		$this->secondaryauthMocks = [ $secondary ];
		$this->initializeManager( true );

		$actual = $this->manager->getAuthenticationRequests( AuthManager::ACTION_LOGIN );
		$expected = [
			$rememberReq,
			$makeReq( "primary-shared", AuthenticationRequest::PRIMARY_REQUIRED ),
			$makeReq( "required", AuthenticationRequest::PRIMARY_REQUIRED ),
			$makeReq( "required2", AuthenticationRequest::PRIMARY_REQUIRED ),
			$makeReq( "optional", AuthenticationRequest::OPTIONAL ),
			$makeReq( "optional2", AuthenticationRequest::OPTIONAL ),
			$makeReq( "foo", AuthenticationRequest::PRIMARY_REQUIRED ),
			$makeReq( "bar", AuthenticationRequest::REQUIRED ),
			$makeReq( "baz", AuthenticationRequest::REQUIRED ),
		];
		usort( $actual, $cmpReqs );
		usort( $expected, $cmpReqs );
		$this->assertEquals( $expected, $actual );

		$this->primaryauthMocks = [ $primary1 ];
		$this->secondaryauthMocks = [ $secondary ];
		$this->initializeManager( true );

		$actual = $this->manager->getAuthenticationRequests( AuthManager::ACTION_LOGIN );
		$expected = [
			$rememberReq,
			$makeReq( "primary-shared", AuthenticationRequest::PRIMARY_REQUIRED ),
			$makeReq( "required", AuthenticationRequest::PRIMARY_REQUIRED ),
			$makeReq( "optional", AuthenticationRequest::OPTIONAL ),
			$makeReq( "foo", AuthenticationRequest::PRIMARY_REQUIRED ),
			$makeReq( "bar", AuthenticationRequest::REQUIRED ),
			$makeReq( "baz", AuthenticationRequest::REQUIRED ),
		];
		usort( $actual, $cmpReqs );
		usort( $expected, $cmpReqs );
		$this->assertEquals( $expected, $actual );
	}

	public function testAllowsPropertyChange() {
		$mocks = [];
		foreach ( [ 'primary', 'secondary' ] as $key ) {
			$class = ucfirst( $key ) . 'AuthenticationProvider';
			$mocks[$key] = $this->createMock( "MediaWiki\\Auth\\Abstract$class" );
			$mocks[$key]->method( 'getUniqueId' )
				->willReturn( $key );
			$mocks[$key]->method( 'providerAllowsPropertyChange' )
				->willReturnCallback( static function ( $prop ) use ( $key ) {
					return $prop !== $key;
				} );
		}

		$this->primaryauthMocks = [ $mocks['primary'] ];
		$this->secondaryauthMocks = [ $mocks['secondary'] ];
		$this->initializeManager( true );

		$this->assertTrue( $this->manager->allowsPropertyChange( 'foo' ) );
		$this->assertFalse( $this->manager->allowsPropertyChange( 'primary' ) );
		$this->assertFalse( $this->manager->allowsPropertyChange( 'secondary' ) );
	}

	public function testAutoCreateOnLogin() {
		$username = self::usernameForCreation();

		$req = $this->createMock( AuthenticationRequest::class );

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'primary' );
		$mock->method( 'beginPrimaryAuthentication' )
			->willReturn( AuthenticationResponse::newPass( $username ) );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( true );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newGood() );

		$mock2 = $this->createMock( AbstractSecondaryAuthenticationProvider::class );
		$mock2->method( 'getUniqueId' )
			->willReturn( 'secondary' );
		$mock2->method( 'beginSecondaryAuthentication' )
			->willReturn( AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ) );
		$mock2->method( 'continueSecondaryAuthentication' )
			->willReturn( AuthenticationResponse::newAbstain() );
		$mock2->method( 'testUserForCreation' )
			->willReturn( StatusValue::newGood() );

		$this->primaryauthMocks = [ $mock ];
		$this->secondaryauthMocks = [ $mock2 ];
		$this->initializeManager( true );
		$this->manager->setLogger( new NullLogger() );
		$session = $this->request->getSession();
		$session->clear();

		$this->assertSame( 0, User::newFromName( $username )->getId() );

		$callback = $this->callback( static function ( $user ) use ( $username ) {
			return $user->getName() === $username;
		} );

		$this->hook( 'UserLoggedIn', UserLoggedInHook::class, $this->never() );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->once() )
			->with( $callback, true );
		$ret = $this->manager->beginAuthentication( [], 'http://localhost/' );
		$this->unhook( 'LocalUserCreated' );
		$this->unhook( 'UserLoggedIn' );
		$this->assertSame( AuthenticationResponse::UI, $ret->status );

		$id = (int)User::newFromName( $username )->getId();
		$this->assertNotSame( 0, User::newFromName( $username )->getId() );
		$this->assertSame( 0, $session->getUser()->getId() );

		$this->hook( 'UserLoggedIn', UserLoggedInHook::class, $this->once() )
			->with( $callback );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->continueAuthentication( [] );
		$this->unhook( 'LocalUserCreated' );
		$this->unhook( 'UserLoggedIn' );
		$this->assertSame( AuthenticationResponse::PASS, $ret->status );
		$this->assertSame( $username, $ret->username );
		$this->assertSame( $id, $session->getUser()->getId() );
	}

	public function testAutoCreateFailOnLogin() {
		$username = self::usernameForCreation();

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'primary' );
		$mock->method( 'beginPrimaryAuthentication' )
			->willReturn( AuthenticationResponse::newPass( $username ) );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mock->method( 'testUserExists' )->willReturn( true );
		$mock->method( 'testUserForCreation' )
			->willReturn( StatusValue::newFatal( 'fail-from-primary' ) );

		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );
		$this->manager->setLogger( new NullLogger() );
		$session = $this->request->getSession();
		$session->clear();

		$this->assertSame( 0, $session->getUser()->getId() );
		$this->assertSame( 0, User::newFromName( $username )->getId() );

		$this->hook( 'UserLoggedIn', UserLoggedInHook::class, $this->never() );
		$this->hook( 'LocalUserCreated', LocalUserCreatedHook::class, $this->never() );
		$ret = $this->manager->beginAuthentication( [], 'http://localhost/' );
		$this->unhook( 'LocalUserCreated' );
		$this->unhook( 'UserLoggedIn' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'authmanager-authn-autocreate-failed', $ret->message->getKey() );

		$this->assertSame( 0, User::newFromName( $username )->getId() );
		$this->assertSame( 0, $session->getUser()->getId() );
	}

	public function testAuthenticationSessionData() {
		$this->initializeManager( true );

		$this->assertNull( $this->manager->getAuthenticationSessionData( 'foo' ) );
		$this->manager->setAuthenticationSessionData( 'foo', 'foo!' );
		$this->manager->setAuthenticationSessionData( 'bar', 'bar!' );
		$this->assertSame( 'foo!', $this->manager->getAuthenticationSessionData( 'foo' ) );
		$this->assertSame( 'bar!', $this->manager->getAuthenticationSessionData( 'bar' ) );
		$this->manager->removeAuthenticationSessionData( 'foo' );
		$this->assertNull( $this->manager->getAuthenticationSessionData( 'foo' ) );
		$this->assertSame( 'bar!', $this->manager->getAuthenticationSessionData( 'bar' ) );
		$this->manager->removeAuthenticationSessionData( 'bar' );
		$this->assertNull( $this->manager->getAuthenticationSessionData( 'bar' ) );

		$this->manager->setAuthenticationSessionData( 'foo', 'foo!' );
		$this->manager->setAuthenticationSessionData( 'bar', 'bar!' );
		$this->manager->removeAuthenticationSessionData( null );
		$this->assertNull( $this->manager->getAuthenticationSessionData( 'foo' ) );
		$this->assertNull( $this->manager->getAuthenticationSessionData( 'bar' ) );
	}

	public function testCanLinkAccounts() {
		$types = [
			PrimaryAuthenticationProvider::TYPE_CREATE => false,
			PrimaryAuthenticationProvider::TYPE_LINK => true,
			PrimaryAuthenticationProvider::TYPE_NONE => false,
		];

		foreach ( $types as $type => $can ) {
			$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
			$mock->method( 'getUniqueId' )->willReturn( $type );
			$mock->method( 'accountCreationType' )
				->willReturn( $type );
			$this->primaryauthMocks = [ $mock ];
			$this->initializeManager( true );
			$this->assertSame( $can, $this->manager->canLinkAccounts(), $type );
		}
	}

	public function testBeginAccountLink() {
		$user = $this->getTestSysop()->getUser();
		$this->initializeManager();

		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_LINK_STATE, 'test' );
		try {
			$this->manager->beginAccountLink( $user, [], 'http://localhost/' );
			$this->fail( 'Expected exception not thrown' );
		} catch ( LogicException $ex ) {
			$this->assertEquals( 'Account linking is not possible', $ex->getMessage() );
		}
		$this->assertNull( $this->request->getSession()->getSecret( AuthManager::ACCOUNT_LINK_STATE ) );

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_LINK );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$ret = $this->manager->beginAccountLink( new User, [], 'http://localhost/' );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'noname', $ret->message->getKey() );

		$ret = $this->manager->beginAccountLink(
			User::newFromName( 'UTDoesNotExist' ), [], 'http://localhost/'
		);
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'authmanager-userdoesnotexist', $ret->message->getKey() );
	}

	public function testContinueAccountLink() {
		$user = $this->getTestSysop()->getUser();
		$this->initializeManager();

		$session = [
			'userid' => $user->getId(),
			'username' => $user->getName(),
			'primary' => 'X',
		];

		try {
			$this->manager->continueAccountLink( [] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( LogicException $ex ) {
			$this->assertEquals( 'Account linking is not possible', $ex->getMessage() );
		}

		$mock = $this->createMock( AbstractPrimaryAuthenticationProvider::class );
		$mock->method( 'getUniqueId' )->willReturn( 'X' );
		$mock->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_LINK );
		$mock->method( 'beginPrimaryAccountLink' )
			->willReturn( AuthenticationResponse::newFail( $this->message( 'fail' ) ) );
		$this->primaryauthMocks = [ $mock ];
		$this->initializeManager( true );

		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_LINK_STATE, null );
		$ret = $this->manager->continueAccountLink( [] );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'authmanager-link-not-in-progress', $ret->message->getKey() );

		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_LINK_STATE,
			[ 'username' => $user->getName() . '<>' ] + $session );
		$ret = $this->manager->continueAccountLink( [] );
		$this->assertSame( AuthenticationResponse::FAIL, $ret->status );
		$this->assertSame( 'noname', $ret->message->getKey() );
		$this->assertNull( $this->request->getSession()->getSecret( AuthManager::ACCOUNT_LINK_STATE ) );

		$id = $user->getId();
		$this->request->getSession()->setSecret( AuthManager::ACCOUNT_LINK_STATE,
			[ 'userid' => $id + 1 ] + $session );
		try {
			$ret = $this->manager->continueAccountLink( [] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( UnexpectedValueException $ex ) {
			$this->assertEquals(
				"User \"{$user->getName()}\" is valid, but ID $id !== " . ( $id + 1 ) . '!',
				$ex->getMessage()
			);
		}
		$this->assertNull( $this->request->getSession()->getSecret( AuthManager::ACCOUNT_LINK_STATE ) );
	}

	/**
	 * @dataProvider provideAccountLink
	 */
	public function testAccountLink(
		StatusValue $preTest, array $primaryResponses, array $managerResponses
	) {
		$user = $this->getTestSysop()->getUser();

		$this->initializeManager();

		// Set up lots of mocks...
		$req = $this->getMockForAbstractClass( AuthenticationRequest::class );
		$mocks = [];

		foreach ( [ 'pre', 'primary' ] as $key ) {
			$class = ucfirst( $key ) . 'AuthenticationProvider';
			$mocks[$key] = $this->getMockBuilder( "MediaWiki\\Auth\\Abstract$class" )
				->setMockClassName( "MockAbstract$class" )
				->getMock();
			$mocks[$key]->method( 'getUniqueId' )
				->willReturn( $key );

			for ( $i = 2; $i <= 3; $i++ ) {
				$mocks[$key . $i] = $this->getMockBuilder( "MediaWiki\\Auth\\Abstract$class" )
					->setMockClassName( "MockAbstract$class" )
					->getMock();
				$mocks[$key . $i]->method( 'getUniqueId' )
					->willReturn( $key . $i );
			}
		}

		$mocks['pre']->method( 'testForAccountLink' )
			->willReturnCallback(
				function ( $u )
					use ( $user, $preTest )
				{
					$this->assertSame( $user->getId(), $u->getId() );
					$this->assertSame( $user->getName(), $u->getName() );
					return $preTest;
				}
			);

		$mocks['pre2']->expects( $this->atMost( 1 ) )->method( 'testForAccountLink' )
			->willReturn( StatusValue::newGood() );

		$mocks['primary']->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_LINK );
		$ct = count( $primaryResponses );
		$callback = $this->returnCallback( function ( $u, $reqs ) use ( $user, $req, &$primaryResponses ) {
			$this->assertSame( $user->getId(), $u->getId() );
			$this->assertSame( $user->getName(), $u->getName() );
			$foundReq = false;
			foreach ( $reqs as $r ) {
				$this->assertSame( $user->getName(), $r->username );
				$foundReq = $foundReq || get_class( $r ) === get_class( $req );
			}
			$this->assertTrue( $foundReq, '$reqs contains $req' );
			return array_shift( $primaryResponses );
		} );
		$mocks['primary']->expects( $this->exactly( min( 1, $ct ) ) )
			->method( 'beginPrimaryAccountLink' )
			->will( $callback );
		$mocks['primary']->expects( $this->exactly( max( 0, $ct - 1 ) ) )
			->method( 'continuePrimaryAccountLink' )
			->will( $callback );

		$abstain = AuthenticationResponse::newAbstain();
		$mocks['primary2']->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_LINK );
		$mocks['primary2']->expects( $this->atMost( 1 ) )->method( 'beginPrimaryAccountLink' )
			->willReturn( $abstain );
		$mocks['primary2']->expects( $this->never() )->method( 'continuePrimaryAccountLink' );
		$mocks['primary3']->method( 'accountCreationType' )
			->willReturn( PrimaryAuthenticationProvider::TYPE_CREATE );
		$mocks['primary3']->expects( $this->never() )->method( 'beginPrimaryAccountLink' );
		$mocks['primary3']->expects( $this->never() )->method( 'continuePrimaryAccountLink' );

		$this->preauthMocks = [ $mocks['pre'], $mocks['pre2'] ];
		$this->primaryauthMocks = [ $mocks['primary3'], $mocks['primary2'], $mocks['primary'] ];
		$this->logger = new TestLogger( true, static function ( $message, $level ) {
			return $level === LogLevel::DEBUG ? null : $message;
		} );
		$this->initializeManager( true );

		$constraint = Assert::logicalOr(
			$this->equalTo( AuthenticationResponse::PASS ),
			$this->equalTo( AuthenticationResponse::FAIL )
		);
		$providers = array_merge( $this->preauthMocks, $this->primaryauthMocks );
		foreach ( $providers as $p ) {
			DynamicPropertyTestHelper::setDynamicProperty( $p, 'postCalled', false );
			$p->expects( $this->atMost( 1 ) )->method( 'postAccountLink' )
				->willReturnCallback( function ( $userArg, $response ) use ( $user, $constraint, $p ) {
					$this->assertInstanceOf( User::class, $userArg );
					$this->assertSame( $user->getName(), $userArg->getName() );
					$this->assertInstanceOf( AuthenticationResponse::class, $response );
					$this->assertThat( $response->status, $constraint );
					DynamicPropertyTestHelper::setDynamicProperty( $p, 'postCalled', $response->status );
				} );
		}

		$first = true;
		$expectLog = [];
		foreach ( $managerResponses as $i => $response ) {
			if ( $response instanceof AuthenticationResponse &&
				$response->status === AuthenticationResponse::PASS
			) {
				$expectLog[] = [ LogLevel::INFO, 'Account linked to {user} by primary' ];
			}

			try {
				if ( $first ) {
					$ret = $this->manager->beginAccountLink( $user, [ $req ], 'http://localhost/' );
				} else {
					$ret = $this->manager->continueAccountLink( [ $req ] );
				}
				if ( $response instanceof Exception ) {
					$this->fail( 'Expected exception not thrown', "Response $i" );
				}
			} catch ( Exception $ex ) {
				if ( !$response instanceof Exception ) {
					throw $ex;
				}
				$this->assertEquals( $response->getMessage(), $ex->getMessage(), "Response $i, exception" );
				$this->assertNull( $this->request->getSession()->getSecret( AuthManager::ACCOUNT_LINK_STATE ),
					"Response $i, exception, session state" );
				return;
			}

			$this->assertSame( 'http://localhost/', $req->returnToUrl );

			$ret->message = $this->message( $ret->message );
			$this->assertResponseEquals( $response, $ret, "Response $i, response" );
			if ( $response->status === AuthenticationResponse::PASS ||
				$response->status === AuthenticationResponse::FAIL
			) {
				$this->assertNull( $this->request->getSession()->getSecret( AuthManager::ACCOUNT_LINK_STATE ),
					"Response $i, session state" );
				foreach ( $providers as $p ) {
					$this->assertSame( $response->status, DynamicPropertyTestHelper::getDynamicProperty( $p, 'postCalled' ),
						"Response $i, post-auth callback called" );
				}
			} else {
				$this->assertNotNull(
					$this->request->getSession()->getSecret( AuthManager::ACCOUNT_LINK_STATE ),
					"Response $i, session state"
				);
				foreach ( $ret->neededRequests as $neededReq ) {
					$this->assertEquals( AuthManager::ACTION_LINK, $neededReq->action,
						"Response $i, neededRequest action" );
				}
				$this->assertEquals(
					$ret->neededRequests,
					$this->manager->getAuthenticationRequests( AuthManager::ACTION_LINK_CONTINUE ),
					"Response $i, continuation check"
				);
				foreach ( $providers as $p ) {
					$this->assertFalse( DynamicPropertyTestHelper::getDynamicProperty( $p, 'postCalled' ), "Response $i, post-auth callback not called" );
				}
			}

			$first = false;
		}

		$this->assertSame( $expectLog, $this->logger->getBuffer() );
	}

	public function provideAccountLink() {
		$req = $this->getMockForAbstractClass( AuthenticationRequest::class );
		$good = StatusValue::newGood();

		return [
			'Pre-link test fail in pre' => [
				StatusValue::newFatal( 'fail-from-pre' ),
				[],
				[
					AuthenticationResponse::newFail( $this->message( 'fail-from-pre' ) ),
				]
			],
			'Failure in primary' => [
				$good,
				$tmp = [
					AuthenticationResponse::newFail( $this->message( 'fail-from-primary' ) ),
				],
				$tmp
			],
			'All primary abstain' => [
				$good,
				[
					AuthenticationResponse::newAbstain(),
				],
				[
					AuthenticationResponse::newFail( $this->message( 'authmanager-link-no-primary' ) )
				]
			],
			'Primary UI, then redirect, then fail' => [
				$good,
				$tmp = [
					AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ),
					AuthenticationResponse::newRedirect( [ $req ], '/foo.html', [ 'foo' => 'bar' ] ),
					AuthenticationResponse::newFail( $this->message( 'fail-in-primary-continue' ) ),
				],
				$tmp
			],
			'Primary redirect, then abstain' => [
				$good,
				[
					$tmp = AuthenticationResponse::newRedirect(
						[ $req ], '/foo.html', [ 'foo' => 'bar' ]
					),
					AuthenticationResponse::newAbstain(),
				],
				[
					$tmp,
					new DomainException(
						'MockAbstractPrimaryAuthenticationProvider::continuePrimaryAccountLink() returned ABSTAIN'
					)
				]
			],
			'Primary UI, then pass' => [
				$good,
				[
					$tmp1 = AuthenticationResponse::newUI( [ $req ], $this->message( '...' ) ),
					AuthenticationResponse::newPass(),
				],
				[
					$tmp1,
					AuthenticationResponse::newPass( '' ),
				]
			],
			'Primary pass' => [
				$good,
				[
					AuthenticationResponse::newPass( '' ),
				],
				[
					AuthenticationResponse::newPass( '' ),
				]
			],
		];
	}

	public function testSetRequestContextUserFromSessionUser() {
		$user = $this->getTestUser()->getUser();
		$context = RequestContext::getMain();
		$context->setUser( $this->getTestUser()->getUser() );
		$context->getRequest()->getSession()->setUser( $user );
		$this->assertSame( $context->getRequest()->getSession()->getUser()->getName(), $context->getUser()->getName() );

		// Update the session with a new user, but leave the context user as the old user
		$newSessionUser = $this->getTestUser( 'sysop' )->getUser();
		$context->getRequest()->getSession()->setUser( $newSessionUser );
		$this->assertNotSame( $newSessionUser->getName(), $context->getUser()->getName() );

		$authManager = $this->getServiceContainer()->getAuthManager();
		$authManager->setRequestContextUserFromSessionUser();
		$this->assertSame( $context->getRequest()->getSession()->getUser()->getName(), $newSessionUser->getName() );
		$this->assertSame( $context->getRequest()->getSession()->getUser()->getName(), $context->getUser()->getName() );
	}
}
