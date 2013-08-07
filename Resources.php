<?php
namespace Luracast\Restler;

use stdClass;

/**
 * API Class to create Swagger Spec 1.1 compatible id and operation
 * listing
 *
 * @category   Framework
 * @package    Restler
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 * @version    3.0.0rc4
 */
class Resources implements iUseAuthentication
{
    /**
     * @var bool should protected resources be shown to unauthenticated users?
     */
    public static $hideProtected = true;
    /**
     * @var bool should we use format as extension?
     */
    public static $useFormatAsExtension = true;
    /**
     * @var array all http methods specified here will be excluded from
     * documentation
     */
    public static $excludedHttpMethods = array('OPTIONS');
    /**
     * @var array all paths beginning with any of the following will be excluded
     * from documentation
     */
    public static $excludedPaths = array();
    /**
     * @var bool
     */
    public static $placeFormatExtensionBeforeDynamicParts = true;
    /**
     * @var bool should we group all the operations with the same url or not
     */
    public static $groupOperations = false;
    /**
     * @var null|callable if the api methods are under access control mechanism
     * you can attach a function here that returns true or false to determine
     * visibility of a protected api method. this function will receive method
     * info as the only parameter.
     */
    public static $accessControlFunction = null;
    /**
     * @var array type mapping for converting data types to javascript / swagger
     */
    public static $dataTypeAlias = array(
        'string' => 'string',
        'int' => 'int',
        'number' => 'float',
        'float' => 'float',
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'NULL' => 'null',
        'array' => 'Array',
        'object' => 'Object',
        'stdClass' => 'Object',
        'mixed' => 'string',
        'DateTime' => 'Date'
    );
    /**
     * @var array configurable symbols to differentiate public, hybrid and
     * protected api
     */
    public static $apiDescriptionSuffixSymbols = array(
        0 => ' &nbsp;', //public api
        1 => ' <strong>&#926;</strong>', //hybrid api
        2 => ' <strong>&#1138;</strong>', //protected api
    );

    /**
     * Injected at runtime
     *
     * @var Restler instance of restler
     */
    public $restler;
    /**
     * @var string when format is not used as the extension this property is
     * used to set the extension manually
     */
    public $formatString = '';
    private $_models;
    private $_bodyParam;
    /**
     * @var bool|stdClass
     */
    private $_fullDataRequested = false;
    private $crud = array(
        'POST' => 'create',
        'GET' => 'retrieve',
        'PUT' => 'update',
        'DELETE' => 'delete',
        'PATCH' => 'partial update'
    );
    private static $prefixes = array(
        'get' => 'retrieve',
        'index' => 'list',
        'post' => 'create',
        'put' => 'update',
        'patch' => 'modify',
        'delete' => 'remove',
    );
    private $_authenticated = false;

    public function __construct()
    {
        if (static::$useFormatAsExtension) {
            $this->formatString = '.{format}';
        }
    }

    /**
     * This method will be called first for filter classes and api classes so
     * that they can respond accordingly for filer method call and api method
     * calls
     *
     *
     * @param bool $isAuthenticated passes true when the authentication is
     *                              done, false otherwise
     *
     * @return mixed
     */
    public function __setAuthenticationStatus($isAuthenticated = false)
    {
        $this->_authenticated = $isAuthenticated;
    }

    /**
     * @access hybrid
     *
     * @param string $id
     *
     * @throws RestException
     * @return null|stdClass
     *
     * @url    GET {id}
     */
    public function get($id = '')
    {
        $version = 1;
        if(false !== ($pos = strpos($id, '-'))){
            $version =  intval(substr($id,$pos+2));
            $id = substr($id,0,$pos);
        }
        if (!Defaults::$useUrlBasedVersioning
            && $version != $this->restler->getRequestedApiVersion()
        ) {
            throw new RestException(404);
        }
        $this->_models = new stdClass();
        $r = null;
        $count = 0;

        $target = empty($id) ? "v$version" : "v$version/$id";

        $routes = Routes::toArray();
        foreach ($routes as $value) {
            foreach ($value as $httpMethod => $route) {
                if (in_array($httpMethod, static::$excludedHttpMethods)) {
                    continue;
                }
                $fullPath = $route['url'];
                if (0 !== strpos($fullPath, $target)) {
                    continue;
                }
                if (strlen($fullPath) != strlen($target) &&
                    0 !== strpos($fullPath, $target . '/')
                ) {
                    continue;
                }
                if (
                    self::$hideProtected
                    && !$this->_authenticated
                    && $route['accessLevel'] > 1
                ) {
                    continue;
                }
                foreach (static::$excludedPaths as $exclude) {
                    if (0 === strpos($fullPath, "v$version/$exclude")) {
                        continue 2;
                    }
                }
                $m = $route['metadata'];
                if ($id == '' && $m['resourcePath'] != "v$version/") {
                    continue;
                }
                if ($this->_authenticated
                    && static::$accessControlFunction
                    && (!call_user_func(
                        static::$accessControlFunction, $route['metadata']))
                ) {
                    continue;
                }
                $count++;
                $className = $this->_noNamespace($route['className']);
                if (!$r) {
                    $resourcePath = '/'
                        . trim($m['resourcePath'], '/');
                    if (!Defaults::$useUrlBasedVersioning) {
                        $resourcePath = str_replace("/v$version", '',
                            $resourcePath);
                    }
                    $r = $this->_operationListing($resourcePath);
                }
                $parts = explode('/', $fullPath);
                $pos = count($parts) - 1;
                if (count($parts) == 1 && $httpMethod == 'GET') {
                } else {
                    for ($i = 0; $i < count($parts); $i++) {
                        if ($parts[$i]{0} == '{') {
                            $pos = $i - 1;
                            break;
                        }
                    }
                }
                $nickname = $this->_nickname($route);
                $parts[self::$placeFormatExtensionBeforeDynamicParts ? $pos : 0]
                    .= $this->formatString;
                // $parts[0] .= $this->formatString; //".{format}";
                if (!Defaults::$useUrlBasedVersioning) {
                    array_shift($parts);
                }
                $fullPath = implode('/', $parts);
                $description = isset(
                $m['classDescription'])
                    ? $m['classDescription']
                    : $className . ' API';
                if (empty($m['description'])) {
                    $m['description'] = $this->restler->getProductionMode()
                        ? ''
                        : 'routes to <mark>'
                        . $route['className']
                        . '::'
                        . $route['methodName'] . '();</mark>';
                }
                if (empty($m['longDescription'])) {
                    $m['longDescription'] = $this->restler->getProductionMode()
                        ? ''
                        : 'Add PHPDoc long description to '
                        . "<mark>$className::"
                        . $route['methodName'] . '();</mark>'
                        . '  (the api method) to write here';
                }
                $operation = $this->_operation(
                    $nickname,
                    $httpMethod,
                    $m['description'] .
                    ($route['accessLevel'] > 2
                        ? static::$apiDescriptionSuffixSymbols[2]
                        : static::$apiDescriptionSuffixSymbols[$route['accessLevel']]
                    ),
                    $m['longDescription']
                );
                if (isset($m['throws'])) {
                    foreach ($m['throws'] as $exception) {
                        $operation->errorResponses[] = array(
                            'reason' => $exception['reason'],
                            'code' => $exception['code']);
                    }
                }
                if (isset($m['param'])) {
                    foreach ($m['param'] as $param) {
                        //combine body params as one
                        $p = $this->_parameter($param);
                        if ($p->paramType == 'body') {
                            $this->_appendToBody($p);
                        } else {
                            $operation->parameters[] = $p;
                        }
                    }
                }
                if (
                    count($this->_bodyParam['description']) ||
                    $this->_fullDataRequested
                ) {
                    $operation->parameters[] = $this->_getBody();
                }
                if (isset($m['return']['type'])) {
                    $responseClass = $m['return']['type'];
                    if (is_string($responseClass)) {
                        if (class_exists($responseClass)) {
                            $this->_model($responseClass);
                            $operation->responseClass
                                = $this->_noNamespace($responseClass);
                        } elseif (strtolower($responseClass) == 'array') {
                            $operation->responseClass = 'Array';
                            $rt = $m['return'];
                            if (isset(
                            $rt[CommentParser::$embeddedDataName]['type'])
                            ) {
                                $rt = $rt[CommentParser::$embeddedDataName]
                                ['type'];
                                if (class_exists($rt)) {
                                    $this->_model($rt);
                                    $operation->responseClass .= '[' .
                                        $this->_noNamespace($rt) . ']';
                                }
                            }
                        }
                    }
                }
                $api = false;

                if(static::$groupOperations){
                    foreach ($r->apis as $a) {
                        if ($a->path == "/$fullPath") {
                            $api = $a;
                            break;
                        }
                    }
                }

                if (!$api) {
                    $api = $this->_api("/$fullPath", $description);
                    $r->apis[] = $api;
                }

                $api->operations[] = $operation;
            }
        }
        if (!$count) {
            throw new RestException(404);
        }
        if (!is_null($r))
            $r->models = $this->_models;
        usort(
           $r->apis,
            function($a, $b){
                $order = array(
                    'GET' => 1,
                    'POST' => 2,
                    'PUT' => 3,
                    'PATCH' => 4,
                    'DELETE' => 5
                );
                return
                    $order[$a->operations[0]->httpMethod]
                    >
                    $order[$b->operations[0]->httpMethod];

            }
        );
        return $r;
    }

    protected function _nickname(array $route)
    {
        $method = $route['methodName'];
        if(isset(self::$prefixes[$method])){
            $method = self::$prefixes[$method];
        } else {
            $method = str_replace(
                array_keys(self::$prefixes),
                array_values(self::$prefixes),
                $method
            );
        }
        return $method;
    }

    private function _noNamespace($className)
    {
        $className = explode('\\', $className);
        return end($className);
    }

    private function _operationListing($resourcePath = '/')
    {
        $r = $this->_resourceListing();
        $r->resourcePath = $resourcePath;
        $r->models = new stdClass();
        return $r;
    }

    private function _resourceListing()
    {
        $r = new stdClass();
        $r->apiVersion = (string)$this->restler->getApiVersion();
        $r->swaggerVersion = "1.1";
        $r->basePath = $this->restler->getBaseUrl();
        $r->apis = array();
        return $r;
    }

    private function _api($path, $description = '')
    {
        $r = new stdClass();
        $r->path = $path;
        $r->description =
            empty($description) && $this->restler->getProductionMode()
                ? 'Use PHPDoc comment to describe here'
                : $description;
        $r->operations = array();
        return $r;
    }

    private function _operation(
        $nickname,
        $httpMethod = 'GET',
        $summary = 'description',
        $notes = 'long description',
        $responseClass = 'void'
    )
    {
        //reset body params
        $this->_bodyParam = array(
            'required' => false,
            'description' => array()
        );

        $r = new stdClass();
        $r->httpMethod = $httpMethod;
        $r->nickname = $nickname;
        $r->responseClass = $responseClass;

        $r->parameters = array();

        $r->summary = $summary;
        $r->notes = $notes;

        $r->errorResponses = array();
        return $r;
    }

    private function _parameter($param)
    {
        $r = new stdClass();
        $r->name = $param['name'];
        $r->description = !empty($param['description'])
            ? $param['description'] . '.'
            : ($this->restler->getProductionMode()
                ? ''
                : 'add <mark>@param {type} $' . $r->name
                . ' {comment}</mark> to describe here');
        //paramType can be path or query or body or header
        $r->paramType = isset($param['from']) ? $param['from'] : 'query';
        $r->required = isset($param['required']) && $param['required'];
        if (isset($param['default'])) {
            $r->defaultValue = $param['default'];
        } elseif (isset($param[CommentParser::$embeddedDataName]['example'])) {
            $r->defaultValue
                = $param[CommentParser::$embeddedDataName]['example'];
        }
        $r->allowMultiple = false;
        $type = 'string';
        if (isset($param['type'])) {
            $type = $param['type'];
            if (is_array($type)) {
                $type = array_shift($type);
            }
            if ($type != 'array' && Util::isObjectOrArray($type)) {
                $this->_model($type);
            } elseif (isset(static::$dataTypeAlias[$type])) {
                $type = static::$dataTypeAlias[$type];
            }
        }
        $r->dataType = $type;
        if (isset($param[CommentParser::$embeddedDataName])) {
            $p = $param[CommentParser::$embeddedDataName];
            if (isset($p['min']) && isset($p['max'])) {
                $r->allowableValues = array(
                    'valueType' => 'RANGE',
                    'min' => $p['min'],
                    'max' => $p['max'],
                );
            } elseif (isset($p['choice'])) {
                $r->allowableValues = array(
                    'valueType' => 'LIST',
                    'values' => $p['choice']
                );
            }
        }
        return $r;
    }

    private function _appendToBody($p)
    {
        if ($p->name === Defaults::$fullRequestDataName) {
            $this->_fullDataRequested = $p;
            unset($this->_bodyParam['names'][Defaults::$fullRequestDataName]);
            return;
        }
        $this->_bodyParam['description'][$p->name]
            = "$p->name"
            . ' : <tag>' . $p->dataType. '</tag> '
            . ($p->required ? ' <i>(required)</i> - ' : ' - ')
            . $p->description;
        $this->_bodyParam['required'] = $p->required
            || $this->_bodyParam['required'];
        $this->_bodyParam['names'][$p->name] = $p;
    }

    private function _getBody()
    {
        $r = new stdClass();
        $n = array_values($this->_bodyParam['names']);
        if (count($n) == 1 && isset($this->_models->{$n[0]->dataType})) {
            $r = $n[0];
            $c = $this->_models->{$r->dataType};
            $a = $c->properties;
            $r->description = "Paste JSON data here";
            if(count($a)){
                $r->description .= " with the following"
                    . (count($a) > 1 ? ' properties.' : ' property.');
                foreach($a as $k => $v){
                    $r->description .= "<hr/>$k : <tag>"
                        . $v['type'] . '</tag> '
                        . (isset($v['required']) ? '(required)' : '')
                        . ' - '.$v['description'];
                }
            }
            $r->defaultValue = "{\n    \""
                . implode("\": \"\",\n    \"",
                    array_keys($c->properties))
                . "\": \"\"\n}";
            return $r;
        }
        $p = array_values($this->_bodyParam['description']);
        $r->name = 'REQUEST_BODY';
        $r->description = "Paste JSON data here";
        if (count($p)==0 && $this->_fullDataRequested) {
            $r->required = $this->_fullDataRequested->required;
            $r->defaultValue = "{\n    \"property\" : \"\"\n}";
        } else {
            $r->description .= " with the following"
                . (count($p) > 1 ? ' properties.' : ' property.')
                . '<hr/>'
                . implode("<hr/>", $p);
            $r->required = $this->_bodyParam['required'];
            $r->defaultValue = "{\n    \""
                . implode("\": \"\",\n    \"",
                    array_keys($this->_bodyParam['names']))
                . "\": \"\"\n}";
        }
        $r->paramType = 'body';
        $r->allowMultiple = false;
        $r->dataType = 'Object';
        return $r;
    }

    private function _model($className, $instance = null)
    {
        $id = $this->_noNamespace($className);
        if(isset($this->_models->{$id})){
            return;
        }
        $properties = array();
        if (!$instance) {
            $instance = new $className();
        }
        $data = get_object_vars($instance);
        $reflectionClass = new \ReflectionClass($className);
        foreach ($data as $key => $value) {

            $propertyMetaData = null;

            try {
                $property = $reflectionClass->getProperty($key);
                if ($c = $property->getDocComment()) {
                    $propertyMetaData = Util::nestedValue(
                        CommentParser::parse($c),
                        'var'
                    );
                }
            } catch (\ReflectionException $e) {
            }

            if (is_null($propertyMetaData)) {
                $type = $this->getType($value, true);
                $description = '';
            } else {
                $type = Util::nestedValue(
                    $propertyMetaData,
                    'type'
                ) ? : $this->getType($value, true);
                $description = Util::nestedValue(
                    $propertyMetaData,
                    'description'
                ) ? : '';

                if (class_exists($type)) {
                    $this->_model($type);
                }
            }

            if (isset(static::$dataTypeAlias[$type])) {
                $type = static::$dataTypeAlias[$type];
            }
            $properties[$key] = array(
                'type' => $type,
                'description' => $description
            );
            if(Util::nestedValue(
                $propertyMetaData,
                CommentParser::$embeddedDataName,
                'required'
            )){
                $properties[$key]['required'] = true;
            }
            if ($type == 'Array') {
                $itemType = count($value)
                    ? $this->getType(end($value), true)
                    : 'string';
                $properties[$key]['item'] = array(
                    'type' => $itemType,
                    /*'description' => '' */ //TODO: add description
                );
            } else if (preg_match('/^Array\[(.+)\]$/', $type, $matches)) {
                $itemType = $matches[1];
                $properties[$key]['type'] = 'Array';
                $properties[$key]['item']['type'] = $itemType;

                if (class_exists($itemType)) {
                    $this->_model($itemType);
                }
            }
        }
        if (!empty($properties)) {
            $model = new stdClass();
            $model->id = $id;
            $model->properties = $properties;
            $this->_models->{$id} = $model;
        }
    }

    /**
     * Find the data type of the given value.
     *
     *
     * @param mixed $o              given value for finding type
     *
     * @param bool  $appendToModels if an object is found should we append to
     *                              our models list?
     *
     * @return string
     *
     * @access private
     */
    public function getType($o, $appendToModels = false)
    {
        if (is_object($o)) {
            $oc = get_class($o);
            if ($appendToModels) {
                $this->_model($oc, $o);
            }
            return $this->_noNamespace($oc);
        }
        if (is_array($o)) {
            if (count($o)) {
                $child = end($o);
                if (Util::isObjectOrArray($child)) {
                    $childType = $this->getType($child, $appendToModels);
                    return "Array[$childType]";
                }
            }
            return 'array';
        }
        if (is_bool($o)) return 'boolean';
        if (is_numeric($o)) return is_float($o) ? 'float' : 'int';
        return 'string';
    }

    /**
     * @access hybrid
     * @return \stdClass
     */
    public function index()
    {
        $r = $this->_resourceListing();
        $map = array();
        $allRoutes = Routes::toArray();
        if (isset($allRoutes['*'])) {
            $this->_mapResources($allRoutes['*'], $map);
            unset($allRoutes['*']);
        }
        $this->_mapResources($allRoutes, $map);
        foreach ($map as $path => $description) {
            if(false === strpos($path,'{')){
                //add id
                $r->apis[] = array(
                    'path' => "/resources/{$path}$this->formatString",
                    'description' => $description
                );
            }
        }
        return $r;
    }

    private function _mapResources(array $allRoutes, array &$map)
    {
        foreach ($allRoutes as $fullPath => $routes) {
            foreach ($routes as $httpMethod => $route) {
                if (in_array($httpMethod, static::$excludedHttpMethods)) {
                    continue;
                }
                if (
                    self::$hideProtected
                    && !$this->_authenticated
                    && $route['accessLevel'] > 1
                ) {
                    continue;
                }
                $path = explode('/', $fullPath);

                $resource = isset($path[1]) ? $path[1] : '';

                $version = intval(substr($path[0], 1));

                if ($resource == 'resources'
                    || (!Defaults::$useUrlBasedVersioning
                        && $version != $this->restler->getRequestedApiVersion())
                ) {
                    continue;
                }

                foreach (static::$excludedPaths as $exclude) {
                    if (0 === strpos($fullPath, "v$version/$exclude")) {
                        continue 2;
                    }
                }

                if ($this->_authenticated
                    && static::$accessControlFunction
                    && (!call_user_func(
                        static::$accessControlFunction, $route['metadata']))
                ) {
                    continue;
                }

                $resource = $resource
                    ? ($version == 1 ? $resource : $resource . "-v$version")
                    : "v$version";

                if (empty($map[$resource])) {
                    $map[$resource] = isset(
                    $route['metadata']['classDescription'])
                        ? $route['metadata']['classDescription'] : '';
                }
            }
        }
    }
}

