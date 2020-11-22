<?php


namespace Spatial\Core;


use Exception;
use JetBrains\PhpStorm\Pure;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Spatial\Common\BindSourceAttributes\FromBody;
use Spatial\Common\BindSourceAttributes\FromForm;
use Spatial\Common\BindSourceAttributes\FromHeader;
use Spatial\Common\BindSourceAttributes\FromQuery;
use Spatial\Common\BindSourceAttributes\FromRoute;
use Spatial\Common\BindSourceAttributes\FromServices;
use Spatial\Common\HttpAttributes\HttpDelete;
use Spatial\Common\HttpAttributes\HttpGet;
use Spatial\Common\HttpAttributes\HttpHead;
use Spatial\Common\HttpAttributes\HttpPatch;
use Spatial\Common\HttpAttributes\HttpPost;
use Spatial\Common\HttpAttributes\HttpPut;
use Spatial\Core\Attributes\ApiController;
use Spatial\Core\Attributes\ApiModule;
use Spatial\Core\Attributes\Route;
use Spatial\Core\Attributes\Area;
use Spatial\Core\Interfaces\IApplicationBuilder;
use Spatial\Core\Interfaces\IRouteModule;
use Spatial\Router\RouterModule;

/**
 * Class App
 * @package Spatial\Core
 */
class App
{
    private IApplicationBuilder $applicationBuilder;
    private IRouteModule $routerModule;
    /**
     * @var array|string[]
     */
    private array $httpVerbs = [
        'HttpGet' => HttpGet::class,
        'HttpPost' => HttpPost::class,
        'HttpPut' => HttpPut::class,
        'HttpDelete' => HttpDelete::class,
        'HttpHead' => HttpHead::class,
        'HttpPatch' => HttpPatch::class,
    ];


    /**
     * For Conventional routing -> pattern: "{controller=Home}/{action=Index}/{id?}"
     * For Attributes -> [Route("[controller]/[action]")]
     * @var array|string[]
     */
    private array $reservedRoutingNames = [
        'action',
        'area',
        'controller',
        'handler',
        'page'
    ];

    private array $reservedBindingSourceAttributes = [
        'FromBody' => FromBody::class,
        'FromForm' => FromForm::class,
        'FromHeader' => FromHeader::class,
        'FromQuery' => FromQuery::class,
        'FromRoute' => FromRoute::class,
        'FromServices' => FromServices::class,
    ];
    /**
     * Keep Track of Imported Modules,
     * Inherit their exports to one $declarations
     * module declarations that are not exported are kept in the module
     * scope for routing and functional use.
     * Providers hold @Injectables for module declarations
     * @var array
     */
    private array $importModules = [];


    /**
     * Includes declarations from the appModules
     * and exports from imported modules
     * @var array
     */
    private array $providers = [];

    /**
     * Modules declarations with imported declarations from import module's exports
     * @var array
     */
    private array $declarations = [];

    private array $controllers = [];
    private array $routetable = [];
    private array $pipes = [];


    private array $baseRouteTemplate = [''];
    private string $uri;
    private array $patternArray;
    private object $defaults;

    public array $status = ['code' => 401, 'reason' => 'Unauthorized'];

    private array $routeTemplateArr = [];
    private array $routeTemplateParams = [];
    private bool $enableAttributeRouting = false;
    private bool $enableConventionalRouting = false;
    private int $routeType = 2;

    private string $requestedMethod;
    /**
     * @var mixed
     */
    private array $routeActivated;


    public function __construct(
        ?string $uri = null
    ) {
        $this->uri = $this->_formatRoute($uri ?? $_SERVER['REQUEST_URI']);

        $this->patternArray['uri'] = explode('/', trim(urldecode($this->uri), '/'));
        $this->patternArray['count'] = count($this->patternArray['uri']);


//        bootstraps app
        $this->applicationBuilder = new ApplicationBuilder();
        $this->routerModule = new RouterModule();
    }

    /**
     * @param $uri
     * @return string
     */
    #[Pure]
    private function _formatRoute(
        $uri
    ): string {
        // Strip query string (?foo=bar) and decode URI
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);

        if ($uri === '') {
            return '/';
        }
        return $uri;
    }


    /**
     * @param string $appModule
     * @return App|null expects all params to have an attribute
     * expects all params to have an attribute
     * @throws ReflectionException
     * @throws Exception
     */
    public function bootstrapModule(string $appModule): ?self
    {
        $reflectionClass = new ReflectionClass($appModule);
        $reflectionClassApiAttributes = $reflectionClass->getAttributes(ApiModule::class);

        if (count($reflectionClassApiAttributes) === 0) {
            return $this;
        }

//        print_r($reflectionClassApiAttributes[0]->newInstance());
        $apiModuleAttributes = $reflectionClassApiAttributes[0]->newInstance();

//        $apiModule = $apiModuleAttributes[0];

//        load attribute metadata
        $this->resolveAppModule($apiModuleAttributes);

//          load configs
        $baseModule = $reflectionClass->newInstance();
        $baseModule->configure($this->applicationBuilder);
        $this->runApp();


        return null;
    }


    /**
     * Make sure to avoid circle imports
     * @param ApiModule $app
     * @return void
     * @throws ReflectionException
     */
    private function resolveAppModule(ApiModule $app): void
    {
//        find the import with routeModule
//        echo 'resoliving imports \n';
        $this->resolveImports($app->imports);

//        Dependency Injection Services
//        echo 'resoliving providers \n';
        $this->resolveProviders($app->providers);

//        Declarations
//        echo 'resoliving declarations \n';
        $this->resolveDeclarations($app->declarations);
//        $routeModule->render();

    }

    /**
     * @param array $moduleProviders
     * @throws ReflectionException
     */
    private function resolveProviders(array $moduleProviders): void
    {
        foreach ($moduleProviders as $provider) {
            if (!isset($this->providers[$provider])) {
                //            check if it has DI for provider
                $this->providers[$provider] = new ReflectionClass($provider);
            }
        }
    }


    /**
     * @param array $moduleDeclarations
     * @throws ReflectionException
     */
    private function resolveDeclarations(array $moduleDeclarations): void
    {
        foreach ($moduleDeclarations as $declaration) {
            if (!isset($this->declarations[$declaration])) {
                //            check if it has DI for provider
                $this->declarations[$declaration] = new ReflectionClass($declaration);
            }
        }
    }

    /**
     * @param array $moduleImports
     * @return void
     * @throws ReflectionException
     */
    private function resolveImports(array $moduleImports): void
    {
        foreach ($moduleImports as $module) {
            if (isset($this->importModules[$module])) {
                throw new \RuntimeException('Import Module ' . $module . ' is already imported in module');
            }
//            check if it has DI for provider
            $this->importModules[$module] = new ReflectionClass($module);
        }
    }

    public function catch(callable $exceptionCallable): void
    {
        $exceptionCallable();
    }

    /**
     * @param array $routeUriArr
     * @param string $token
     * @return bool
     */
    public function isUriRoute(array $routeUriArr, string $token = '{}'): bool
    {
        $isMatch = true;
//        print_r($this->patternArray);
//        print_r($routeUriArr);
        $routeArrCount = count($routeUriArr);

        if ($routeArrCount < $this->patternArray['count']) {
            return false;
        }


        for ($i = 0; $i < $routeArrCount; $i++) {
            $isToken = str_starts_with($routeUriArr[$i], $token[0]);
//            echo 'hello ghana';
            if (!$isToken) {
                if (
                    !isset($this->patternArray['uri'][$i]) ||
                    !($this->patternArray['uri'][$i] === $routeUriArr[$i])
                ) {
                    $isMatch = false;
                    break;
                }
//                echo $this->patternArray['uri'][$i] . '===' . $routeUriArr[$i];
                continue;
            }


            $placeholder =
                str_replace(
                    [$token[0], $token[1]],
                    '',
                    $routeUriArr[$i]
                );

//            echo '<br/> placeholder for token is --> ' . $placeholder;

            // check to see if its the last placeholder
            // AND if the placeholder is prefixed with `...`
            // meaning the placeholder is an array of the rest of the uriArr member
            if ($i === ($routeArrCount - 1) && str_starts_with($placeholder, '...')) {
                $placeholder = ltrim($placeholder, '/././.');
                if (isset($this->patternArray['uri'][$i])) {
                    for ($uri = $i, $uriMax = count($this->patternArray['uri']); $uri < $uriMax; $uri++) {
                        $this->replaceRouteToken($placeholder, $this->patternArray['uri'][$uri], true);
                    }
                }
                break;
            }
            $this->replaceRouteToken($placeholder, $this->patternArray['uri'][$i] ?? null);
        }

        return $isMatch;
    }

    /**
     * @param string $placeholderString
     * @param string|null $uriValue
     * @param bool $isList
     */
    private function replaceRouteToken(string $placeholderString, ?string $uriValue, bool $isList = false): void
    {
        // separate constraints
        $placeholder = explode(':', $placeholderString);

        $value = $uriValue ?? $this->defaults->{$placeholder[0]} ?? null;

        if (isset($placeholder[1])) {
            $typeValue = explode('=', $placeholder[1]);
            if (isset($typeValue[1])) {
                $value = $value ?? $typeValue[1];
            }
            if ($value !== null) {
                $value = match ($placeholder[1]) {
                    'int' => (int)$value,
                    'bool' => (bool)$value,
                    'array' => (array)$value,
                    'float' => (float)$value,
                    'object' => (object)$value,
                    default => (string)$value,
                };
            }
        }
        // set value
        $isList ?
            $this->defaults->{$placeholder[0]}[] = $value :
            $this->defaults->{$placeholder[0]} = $value;
    }


    /**
     * @param string $controllerClass
     * @return array
     * @throws ReflectionException
     */
    private function resolveController(string $controllerClass): array
    {
        $reflectionClass = new ReflectionClass($controllerClass);

        $listeners = [];

        foreach ($reflectionClass->getMethods() as $method) {
            $attributes = $method->getAttributes();

            foreach ($attributes as $attribute) {
                $listener = $attribute->newInstance();

                $listeners[] = [
                    // The event that's configured on the attribute
                    $listener->event,

                    // The listener for this event
                    [$controllerClass, $method->getName()],
                ];
            }
        }

        return $listeners;
    }

    /**
     * @return string
     */
    private function getRequestedMethod(): string
    {
//        $method = 'httpGet';


        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $httpRequest = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'];
        } else {
            $httpRequest = $_SERVER['REQUEST_METHOD'];
        }


        return strtolower($httpRequest);
    }

    /**
     *
     * @throws Exception
     */
    private function runApp(): void
    {
        $this->resolveModuleRouting();


        if (count($this->routetable) === 0) {
            $this->createRouteTable();
        }

        $this->requestedMethod = $this->getRequestedMethod();
        $this->defaults = new class {};


        $routeFound = false;
//        $routeActivated;
        foreach ($this->routetable as $route) {
//            echo '<br> is ' . $this->uri . ' === ' . $route['route'];
            $routeArr = explode('/', trim($route['route'], '/'));
            $routeHttp = $route['httpMethod'];
            if (
                str_contains($routeHttp, $this->requestedMethod) ||
                str_contains($routeHttp, 'all')
            ) {
                if ($this->isUriRoute($routeArr)) {
                    $routeFound = true;
                    $this->routeActivated = $route;
                    break;
                }
            }
        }

        if ($routeFound) {
//            echo 'route is found!!!';
//            print_r($this->routeActivated);
//            print_r($this->defaults);
            $this->routerModule->render($this->routeActivated, $this->defaults);
        } else {
            echo 'route was not found, rely on bootstrap is any -> ' .
                $this->requestedMethod . ' - ' . $this->defaults->content;
        }

        if ($this->applicationBuilder->isSwooleHttp) {
            return;
        }

        if ($this->applicationBuilder->isSwooleWebsocket) {
            return;
        }
    }


    /**
     * @throws Exception
     */
    private
    function createRouteTable(): void
    {
//        echo '<pre>';
//        var_dump($this->declarations);

//        separating declarations to
//        controllers
//        pipes
//        directive

        foreach ($this->declarations as $declaration) {
//            echo '<br/> >> Declaration checking is ' . $declaration->getShortName();
            $attr = $declaration->getAttributes(ApiController::class);
            if (count($attr) > 0) {
                $this->registerNewController($declaration);
                // Now try do draw route table

            } else {
//            check parent;
//                    loop through parents
                while ($parent = $declaration->getParentClass()) {
                    if (
                        $parent->getName() === "Spatial\Core\ControllerBase" ||
                        $parent->getName() === "Spatial\Core\Controller"
                    ) {
                        $this->registerNewController($declaration);
                        break;
                    } else {
//                        echo '<br /> checking parent attribute';
                        $parentAttr = $parent->getAttributes(ApiController::class);
                        if (count($parentAttr) > 0) {
                            $this->registerNewController($declaration);
                        }
                        break;
                    }
                }
            }
        }
//        $this->printRouteTable();
    }

    /**
     * Print route tables
     */
    private
    function printRouteTable(): void
    {
        echo '<p> Route Table <br/> >';
        echo '<table style="display: block; background-color: paleturquoise"> 
<thead style="background-color: aliceblue">
<tr>
<th>Route</th>
<th>Controller</th>
<th>Action</th>
<th>Params</th>
<th>HttpVerb</th>

</tr>
</thead>
<tbody>';
        foreach ($this->routetable as $row) {
            echo '
            <tr style="background-color: bisque">
<th>' . $row['route'] . '</th>
<th>' . $row['controller'] . '</th>
<th>' . $row['action'] . '</th>
<th>' . json_encode($row['params']) . '</th>
<th>' . $row['httpMethod'] . '</th>

</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param ReflectionClass $controllerReflection
     * @throws Exception
     */
    private
    function registerNewController(
        ReflectionClass $controllerReflection
    ): void {
        if (isset($this->controllers[$controllerReflection->getName()])) {
//            var_dump($this->controllers);
            throw new \RuntimeException('Controller ' . $controllerReflection->getName() . ' cannot be declared twice');
            return;
        }
        $this->controllers[$controllerReflection->getName()] = $controllerReflection;


        $tokens = [
            'action' => '',
            'area' => $this->getAreaAttribute($controllerReflection->getAttributes(Area::class)) ?? '',
            'controller' => strtolower(str_replace('Controller', '', $controllerReflection->getShortName())),
            'handler' => '',
            'page' => '',
            'httpVerb' => ''
        ];

        switch ($this->routeType) {
            case 1:
                $this->registerControllerRoutes($controllerReflection, $tokens);
                break;
            case 2:
//    attribute routing
                $this->registerAttributeRoute($controllerReflection, $tokens);
                break;

            default:
                $this->registerControllerRoutes($controllerReflection, $tokens);
                $this->registerAttributeRoute($controllerReflection, $tokens);
                break;
        }
    }

    /**
     * @param string $routeTemplate
     */
    private
    function convertRouteTemplateToPattern(
        string $routeTemplate
    ): void {
        $this->routeTemplateArr = explode('/', trim(urldecode($routeTemplate), '/'));
        $baseRoute = '';

        $found = false;

//        conventional routing
        foreach ($this->routeTemplateArr as $routeTX) {
            foreach ($this->reservedRoutingNames as $token) {
                if (str_starts_with($routeTX, '{' . $token)) {
//                    echo '<br /> found ' . $token . ' in ' . $routeTX . ' -->' . $controllerName . '<br /> ';
                    $baseRoute .= '[' . $token . ']/';
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $baseRoute .= $routeTX . '/';
                $found = false;
            }
        }

        echo $baseRoute . '<br/>';
        $this->baseRouteTemplate[] = $baseRoute;
    }

    /**
     * @param ReflectionClass $controllerReflection
     * @param array $tokens
     */
    private
    function registerControllerRoutes(
        ReflectionClass $controllerReflection,
        array $tokens
    ): void {
        $controllerActions = $controllerReflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($controllerActions as $action) {
            if ($action->getName() === '__construct') {
                continue;
            }
            $tokens['action'] = $action->getName();

            foreach ($this->baseRouteTemplate as $baseRoute) {
                $this->setToRouteTable(
                    $controllerReflection->getName(),
                    $baseRoute,
                    $tokens,
                    $action
                );
            }
        }
    }

    /**
     * @param ReflectionClass $controllerReflection
     */
    private
    function registerAttributeRoute(
        ReflectionClass $controllerReflection,
        array $tokens
    ): void {
        $controllerBaseRoute = [''];
        $controllerRoutes = [];
        $controllerRouteAttributes = $controllerReflection->getAttributes(Route::class);
        $controllerActions = $controllerReflection->getMethods(ReflectionMethod::IS_PUBLIC);

//        print_r(
//            [
//                $controllerReflection->getName(),
//                $controllerName,
//                $controllerArea
//            ]
//        );

        if (count($controllerRouteAttributes) > 0) {
            $controllerBaseRoute = [];
            foreach ($controllerRouteAttributes as $baseRouteReflectionAttributes) {
                $routeInstance = $baseRouteReflectionAttributes->newInstance();
                $controllerBaseRoute[] = $routeInstance->template;
            }
        }

        foreach ($controllerActions as $action) {
            if ($action->getName() === '__construct') {
                continue;
            }
            $tokens['action'] = $action->getName();

            $tokens['httpVerb'] = $this->getHttpVerbsFromMethods($action);

            $actionRouteReflectionAttributes = $action->getAttributes(Route::class);
            if (count($actionRouteReflectionAttributes) > 0) {
                foreach ($actionRouteReflectionAttributes as $routeReflectionAttribute) {
                    $routeInstance = $routeReflectionAttribute->newInstance();
                    if (str_starts_with($routeInstance->template, '/')) {
                        $this->setToRouteTable(
                            $controllerReflection->getName(),
                            $routeInstance->template,
                            $tokens,
                            $action
                        );
                    } else {
                        foreach ($controllerBaseRoute as $baseRoute) {
                            $this->setToRouteTable(
                                $controllerReflection->getName(),
                                $baseRoute . $routeInstance->template,
                                $tokens,
                                $action
                            );
                        }
                    }
                }
            } else {
//            now also check if method/action has httpverbs without any route, map it to the table
//                $actionRouteReflectionAttributes = $action->getAttributes(Route::class);
                foreach ($controllerBaseRoute as $baseRoute) {
                    $this->setToRouteTable(
                        $controllerReflection->getName(),
                        $baseRoute,
                        $tokens,
                        $action
                    );
                }
            }
        }
    }

    /**
     * @param array $areaAttribute
     * @return string|null
     */
    private
    function getAreaAttribute(
        array $areaAttribute
    ): ?string {
        if (count($areaAttribute) === 0) {
            return null;
        }
        return $areaAttribute[0]->newInstance()->name;
    }

    /**
     * @param string $controllerClassName
     * @param string $template
     * @param array $tokens
     * @param ReflectionMethod|null $action
     */
    private
    function setToRouteTable(
        string $controllerClassName,
        string $template,
        array $tokens,
        ?ReflectionMethod $action = null
    ): void {
        $template = trim($template, '/');
//        echo 'setting to route table';
//        check for action area attribute. if it exists, overwrite else none
        $tokens['area'] = $this->getAreaAttribute($action->getAttributes(Area::class)) ?? $tokens['area'];

//        go through httpverbs for routing and request methods
        if (count($tokens['httpVerb']) > 0) {
            foreach ($tokens['httpVerb'] as $http) {
//                print_r($http);
                $this->routetable[] = [
                    'route' => $this->replaceTemplateTokens(

                        $http['template'] ? $template . '/' . $http['template'] : $template,
                        $tokens
                    ),
                    'controller' => $controllerClassName,
                    'httpMethod' => $http['event'], // $action ? $this->getHttpVerbsFromMethods($action) : null,
                    'action' => $tokens['action'],
                    'params' => $this->getActionParamsWithAttribute($action)
                ];
            }
            return;
        }
        $this->routetable[] = [
            'route' => $this->replaceTemplateTokens($template, $tokens),
            'controller' => $controllerClassName,
            'httpMethod' => $this->setDefaultHttpMethod($tokens['action']),
            // $action ? $this->getHttpVerbsFromMethods($action) : null,
            'action' => $tokens['action'],
            'params' => $this->getActionParamsWithAttribute($action)
        ];
    }


    /**
     * @param ReflectionMethod|null $action
     * @return array|null
     */
    private function getActionParamsWithAttribute(?ReflectionMethod $action): ?array
    {
        if ($action === null) {
            return null;
        }
        $params = [];

        foreach ($action->getParameters() as $parameter) {
            $actionParam = [
                'param' => $parameter,
                'bindingSource' => null
            ];
            foreach (
                $this->reservedBindingSourceAttributes as $bindName
            => $bindingSource
            ) {
                $attribute = $parameter->getAttributes($bindingSource);
                if (count($attribute) > 0) {
                    $actionParam['bindingSource'] = $bindName;
                    break;
                }
            }

            $actionParam['bindingSource'] ??= 'FromRoute';
            $params[] = $actionParam;
        }

        return $params;
    }

    /**
     * @param string $actionName
     * @return string
     */
    private
    function setDefaultHttpMethod(
        string $actionName
    ): string {
        return match ($actionName) {
            'httpGet' => 'get',
            'httpPost' => 'post',
            'httpPut' => 'put',
            'httpDelete' => 'delete',
            'httpPatch' => 'patch',
            'httpHead' => 'head',
            default => 'all'
        };
    }

    /**
     * @param string $template
     * @param array $tokens
     * @return string
     */
    private
    function replaceTemplateTokens(
        string $template,
        array $tokens
    ): string {
//                replace any part that is reserved with [...];
        foreach ($this->reservedRoutingNames as $tokenKey) {
            if ($tokens[$tokenKey] === null) {
                continue;
            }
            $template = str_replace('[' . $tokenKey . ']', $tokens[$tokenKey], $template);
        }
//        $template = str_replace('[controller]', $controller, $template);

        return '/' . trim(strtolower($template), '/');
    }

    /**
     * @param ReflectionMethod $action
     * @return array
     */
    private
    function getHttpVerbsFromMethods(
        ReflectionMethod $action
    ): array {
        $verbs = [];
        $params = [];


        foreach ($this->httpVerbs as $httpMethod) {
            $params = [];
            $httpGetReflection = $action->getAttributes($httpMethod);

            if (count($httpGetReflection) > 0) {
//                $verbs[$httpMethod] = [];

                foreach ($httpGetReflection as $verb) {
                    $verbInstance = $verb->newInstance();
                    $verbClassReflection = new ReflectionClass($verbInstance);

                    foreach ($verbClassReflection->getProperties() as $property) {
                        $params[$property->getName()] = $verbInstance->{$property->getName()};
                    }

                    $verbs[] = $params;
                }
            }
        }

        return $verbs;
    }

    private
    function resolveModuleRouting(): void
    {
//        get routing settings
        foreach ($this->applicationBuilder->routingType as $routeEndpoint) {
            if (!$routeEndpoint->useAttributeRouting) {
                //        prepare baseRoute
//        To be moved to application builder to use endpoints
                $this->convertRouteTemplateToPattern($routeEndpoint->pattern);
                $this->enableConventionalRouting = true;
                print_r($routeEndpoint->name);
            } else {
                $this->enableAttributeRouting = true;
            }
        }


        if ($this->enableAttributeRouting & $this->enableConventionalRouting) {
            $this->routeType = 0;
        } elseif (!$this->enableAttributeRouting & $this->enableConventionalRouting) {
            $this->routeType = 1;
        } elseif ($this->enableAttributeRouting & !$this->enableConventionalRouting) {
            $this->routeType = 2;
        }
    }
}

