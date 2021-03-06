<?php
///////////////////////////////////////////////////////////////////////////////
//
// © Copyright f-project.net 2010-present.
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
///////////////////////////////////////////////////////////////////////////////

namespace fproject\amf;

use fproject\amf\auth\AuthAbstract;
use fproject\amf\loader\ResourceLoader;
use fproject\amf\reflect\AbstractFunctionReflector;
use fproject\amf\reflect\FunctionReflector;
use fproject\amf\reflect\MethodReflector;
use fproject\amf\reflect\ClassReflector;
use fproject\amf\reflect\ReflectorHelper;
use fproject\amf\value\messaging\AcknowledgeMessage;
use fproject\amf\value\messaging\CommandMessage;
use fproject\amf\value\messaging\ErrorMessage;
use fproject\amf\value\messaging\RemotingMessage;
use fproject\amf\value\MessageHeader;
use fproject\amf\value\MessageBody;
use fproject\amf\parse\TypeLoader;
use fproject\amf\acl\Acl;
use fproject\amf\acl\Resource;
use Exception;
use fproject\amf\auth\Auth;
use fproject\amf\auth\AuthResult;

/**
 * An AMF gateway server implementation to allow the connection of the Adobe Flash Player/Adobe AIR to PHP server sites
 *
 * @todo       Make the reflection methods cache and autoload.
 */
class Server
{
    /**
     * Array of dispatchables
     * @var array
     */
    protected $_methods = [];

    /**
     * Array of classes that can be called without being explicitly loaded
     *
     * Keys are class names.
     *
     * @var array
     */
    protected $_classAllowed = [];

    /**
     * Loader for classes in added directories
     * @var ResourceLoader $_loader
     */
    protected $_loader;

    /**
     * @var bool Production flag; whether or not to return exception messages
     */
    protected $_production = true;

    /**
     * Request processed
     * @var null|Request
     */
    protected $_request = null;

    /**
     * Class to use for responses
     * @var null|Response
     */
    protected $_response;

    /**
     * Dispatch table of name => method pairs
     * @var array
     */
    protected $_table = [];

    /**
     *
     * @var bool session flag; whether or not to add a session to each response.
     */
    protected $_session = false;

    /**
     * Set the default session.name if php_
     * @var string
     */
    protected $_sessionName = 'PHPSESSID';

    /**
     * Authentication handler object
     *
     * @var AuthAbstract
     */
    protected $_auth;
    /**
     * ACL handler object
     *
     * @var Acl
     */
    protected $_acl;
    /**
     * The server constructor
     */
    public function __construct()
    {
        TypeLoader::setResourceLoader(new ResourceLoader(array("Resource" => "amf/parse/resource")));
    }

    /**
     * Set authentication adapter
     *
     * If the authentication adapter implements a "getAcl()" method, populate 
     * the ACL of this instance with it (if none exists already).
     *
     * @param  AuthAbstract $auth
     * @return Server
     */
    public function setAuth(AuthAbstract $auth)
    {
        $this->_auth = $auth;
        if ((null === $this->getAcl()) && method_exists($auth, 'getAcl')) {
            $this->setAcl($auth->getAcl());
        }
        return $this;
    }
   /**
     * Get authentication adapter
     *
     * @return AuthAbstract
     */
    public function getAuth()
    {
        return $this->_auth;
    }

    /**
     * Set ACL adapter
     *
     * @param  Acl $acl
     * @return Server
     */
    public function setAcl(Acl $acl)
    {
        $this->_acl = $acl;
        return $this;
    }
   /**
     * Get ACL adapter
     *
     * @return Acl
     */
    public function getAcl()
    {
        return $this->_acl;
    }

    /**
     * Set production flag
     *
     * @param  bool $flag
     * @return Server
     */
    public function setProduction($flag)
    {
        $this->_production = (bool) $flag;
        return $this;
    }

    /**
     * Whether or not the server is in production
     *
     * @return bool
     */
    public function isProduction()
    {
        return $this->_production;
    }

    /**
     * @param string $namespace
     * @return Server
     * @internal param of $namespace all incoming sessions defaults to Zend_Amf
     */
    public function setSession($namespace = 'Zend_Amf')
    {
        $this->_session = true;
        return $this;
    }

    /**
     * Whether of not the server is using sessions
     * @return bool
     */
    public function isSession()
    {
        return $this->_session;
    }

    /**
     * Check if the ACL allows accessing the function or method
     *
     * @param string|object $object Object or class being accessed
     * @param string $function Function or method being accessed
     * @return bool
     * @throws AmfException
     * @throws AmfException
     */
    protected function _checkAcl($object, $function)
    {
        if(!$this->_acl) {
            return true;
        }
        if($object) {
            $class = is_object($object)?get_class($object):$object;
            if(!$this->_acl->has($class)) {
                $this->_acl->addResource(new Resource($class));
            }
            $call = array($object, "initAcl");
            if(is_callable($call) && !call_user_func($call, $this->_acl)) {
                // if initAcl returns false, no ACL check
                return true;
            }
        } else {
            $class = null;
        }

        $auth = Auth::getInstance();
        if($auth->hasIdentity()) {
            $role = $auth->getIdentity()->role;
        } else {
            if($this->_acl->hasRole(Constants::GUEST_ROLE)) {
                $role = Constants::GUEST_ROLE;
            } else {
                throw new AmfException("Unauthenticated access not allowed");
            }
        }
        if($this->_acl->isAllowed($role, $class, $function)) {
            return true;
        } else {
            throw new AmfException("Access not allowed");
        }
    }

    /**
     * Get PluginLoader for the Server
     *
     * @return ResourceLoader
     */
    protected function getLoader()
    {
        if(empty($this->_loader)) {
            $this->_loader = new ResourceLoader();
        }
        return $this->_loader;
    }

    /**
     * Loads a remote class or method and executes the function and returns
     * the result
     *
     * @param  string $method Is the method to execute
     * @param null|array $params argument values for the method
     * @param null|array $source
     * @return mixed $response the result of executing the method
     * @throws AmfException
     */
    protected function _dispatch($method, $params = null, $source = null)
    {
        if($source) {
            if(($mapped = TypeLoader::getMappedClassName($source)) !== false) {
                $source = $mapped;
            }
        }
        $qualifiedName = empty($source) ? $method : $source . '.' . $method;

        if (!isset($this->_table[$qualifiedName])) {
            // if source is null a method that was not defined was called.
            if ($source) {
                $className = str_replace('.', '_', $source);
                if(class_exists($className, false) && !isset($this->_classAllowed[$className])) {
                    throw new AmfException('Can not call "' . $className . '" - use setClass()');
                }
                try {
                    $this->getLoader()->load($className);
                } catch (Exception $e) {
                    throw new AmfException('Class "' . $className . '" does not exist: '.$e->getMessage(), 0, $e);
                }
                // Add the new loaded class to the server.
                $this->setClass($className, $source);
            }

            if (!isset($this->_table[$qualifiedName])) {
                // Source is null or doesn't contain specified method
                throw new AmfException('Method "' . $method . '" does not exist');
            }
        }

        $info = $this->_table[$qualifiedName];
        $argv = $info->getInvokeArguments();

        if (0 < count($argv)) {
            $params = array_merge($params, $argv);
        }

        $params = $this->_castParameters($info, $params);

        if ($info instanceof FunctionReflector) {
            $func = $info->getName();
            $this->_checkAcl(null, $func);
            $return = call_user_func_array($func, $params);
        } elseif ($info instanceof MethodReflector) {
            // Get class
            $class = $info->getDeclaringClass()->getName();
            if ('static' == $info->isStatic()) {
                // for some reason, invokeArgs() does not work the same as
                // invoke(), and expects the first argument to be an object.
                // So, using a callback if the method is static.
                $this->_checkAcl($class, $info->getName());
                $return = call_user_func_array(array($class, $info->getName()), $params);
            } else {
                // Object methods
                try {
                    $object = $info->getDeclaringClass()->newInstance();
                } catch (Exception $e) {
                    throw new AmfException('Error instantiating class ' . $class . ' to invoke method ' . $info->getName() . ': '.$e->getMessage(), 621, $e);
                }
                $this->_checkAcl($object, $info->getName());
                $return = $info->invokeArgs($object, $params);
            }
        } else {
            throw new AmfException('Method missing implementation ' . get_class($info));
        }

        return $return;
    }

    /**
     * Handles each of the 11 different command message types.
     *
     * A command message is a flex.messaging.messages.CommandMessage
     *
     * @see    CommandMessage
     * @param  CommandMessage $message
     * @return AcknowledgeMessage
     * @throws AmfException
     */
    protected function _loadCommandMessage(CommandMessage $message)
    {
        switch($message->operation) {
            case CommandMessage::DISCONNECT_OPERATION :
            case CommandMessage::CLIENT_PING_OPERATION :
                $return = new AcknowledgeMessage($message);
                break;
            case CommandMessage::LOGIN_OPERATION :
                $data = explode(':', base64_decode($message->body));
                $userid = $data[0];
                $password = isset($data[1])?$data[1]:"";
                /*if(empty($userid)) {
                    throw new AmfException('Login failed: username not supplied');
                }*/

                $authResult = $this->_handleAuth($userid, $password);

                //No need to check the result: if authentication is failed, an error is already thrown!
                /*if(!$this->_handleAuth($userid, $password)) {
                    throw new AmfException('Authentication failed');
                }*/

                $return = new AcknowledgeMessage($message);
                if(property_exists($authResult->getIdentity(), 'token'))
                {
                    $return->body = $authResult->getIdentity()->id.':'.$authResult->getIdentity()->token;
                }
                break;
           case CommandMessage::LOGOUT_OPERATION :
                if($this->_auth) {
                    Auth::getInstance()->clearIdentity();
                }
                $return = new AcknowledgeMessage($message);
                break;
            default :
                throw new AmfException('CommandMessage::' . $message->operation . ' not implemented');
                break;
        }
        return $return;
    }

    /**
     * Create appropriate error message
     *
     * @param int $objectEncoding Current AMF encoding
     * @param string $message Message that was being processed when error happened
     * @param string $description Error description
     * @param mixed $detail Detailed data about the error
     * @param int $code Error code
     * @param int $line Error line
     * @return ErrorMessage|array
     */
    protected function _errorMessage($objectEncoding, $message, $description, $detail, $code, $line)
    {
        $return = null;
        switch ($objectEncoding) {
            case Constants::AMF0_OBJECT_ENCODING :
                return array (
                        'description' => ($this->isProduction ()) ? '' : $description,
                        'detail' => ($this->isProduction ()) ? '' : $detail,
                        'line' => ($this->isProduction ()) ? 0 : $line,
                        'code' => $code
                );
            case Constants::AMF3_OBJECT_ENCODING :
                $return = new ErrorMessage ( $message );
                $return->faultString = $this->isProduction () ? '' : $description;
                $return->faultCode = $code;
                $return->faultDetail = $this->isProduction () ? '' : $detail;
                break;
        }
        return $return;
    }

    /**
     * Handle AMF authentication
     *
     * @param string $userId
     * @param string $password
     * @return bool|AuthResult
     * @throws AmfException
     *
     */
    protected function _handleAuth( $userId,  $password)
    {
        if (!$this->_auth) {
            return true;
        }
        $this->_auth->setCredentials($userId, $password);
        $auth = Auth::getInstance();
        $result = $auth->authenticate($this->_auth);
        if ($result->isValid()) {
            if (!$this->isSession()) {
                $this->setSession();
            }
            return $result;
        } else {
            // authentication failed, good bye
            throw new AmfException(
                "Authentication failed: " . join("\n",
                    $result->getMessages()), $result->getCode());
        }

    }

    /**
     * Takes the de_errorMessageserialized AMF request and performs any operations.
     *
     * @todo   should implement and SPL observer pattern for custom AMF headers
     * @todo   DescribeService support
     * @param  Request $request
     * @return Response
     * @throws AmfException|Exception
     */
    protected function _handle(Request $request)
    {
        // Get the object encoding of the request.
        $objectEncoding = $request->getObjectEncoding();

        // create a response object to place the output from the services.
        $response = $this->getResponse();

        // set response encoding
        $response->setObjectEncoding($objectEncoding);

        // Authenticate, if we have credential headers
        $error   = false;
        $headers = $request->getAmfHeaders();
        if (isset($headers[Constants::CREDENTIALS_HEADER]) 
            /*&& isset($headers[Constants::CREDENTIALS_HEADER]->userid)*/
            && isset($headers[Constants::CREDENTIALS_HEADER]->password)
        ) {
            try {
                $authResult = $this->_handleAuth(
                    $headers[Constants::CREDENTIALS_HEADER]->userid,
                    $headers[Constants::CREDENTIALS_HEADER]->password
                );
                if ($authResult === true || $authResult->getCode() == AuthResult::SUCCESS) {
                    // use RequestPersistentHeader to clear credentials
                    $response->addAmfHeader(
                        new MessageHeader(
                            Constants::PERSISTENT_HEADER,
                            false,
                            new MessageHeader(
                                Constants::CREDENTIALS_HEADER,
                                false, null
                            )
                        )
                    );
                }
            } catch (Exception $e) {
                // Error during authentication; report it
                $error = $this->_errorMessage(
                    $objectEncoding, 
                    '', 
                    $e->getMessage(),
                    $e->getTraceAsString(),
                    $e->getCode(),
                    $e->getLine()
                );
                $responseType = Constants::STATUS_METHOD;
            }
        }

        // Iterate through each of the service calls in the AMF request
        foreach($request->getAmfBodies() as $body)
        {
            if ($error) {
                // Error during authentication; just report it and be done
                $responseURI = $body->getResponseURI() . $responseType;
                $newBody     = new MessageBody($responseURI, null, $error);
                $response->addAmfBody($newBody);
                continue;
            }
            try {
                switch ($objectEncoding) {
                    case Constants::AMF0_OBJECT_ENCODING:
                        // AMF0 Object Encoding
                        $targetURI = $body->getTargetURI();
                        $message = '';

                        // Split the target string into its values.
                        $source = substr($targetURI, 0, strrpos($targetURI, '.'));

                        if ($source) {
                            // Break off method name from namespace into source
                            $method = substr(strrchr($targetURI, '.'), 1);
                            $return = $this->_dispatch($method, $body->getData(), $source);
                        } else {
                            // Just have a method name.
                            $return = $this->_dispatch($targetURI, $body->getData());
                        }
                        break;
                    case Constants::AMF3_OBJECT_ENCODING:
                    default:
                        // AMF3 read message type
                        $message = $body->getData();
                        if ($message instanceof CommandMessage) {
                            // async call with command message
                            $return = $this->_loadCommandMessage($message);
                        } elseif ($message instanceof RemotingMessage) {
                            $return = new AcknowledgeMessage($message);
                            $return->body = $this->_dispatch($message->operation, $message->body, $message->source);
                        } else {
                            // Amf3 message sent with netConnection
                            $targetURI = $body->getTargetURI();

                            // Split the target string into its values.
                            $source = substr($targetURI, 0, strrpos($targetURI, '.'));

                            if ($source) {
                                // Break off method name from namespace into source
                                $method = substr(strrchr($targetURI, '.'), 1);
                                $return = $this->_dispatch($method, $body->getData(), $source);
                            } else {
                                // Just have a method name.
                                $return = $this->_dispatch($targetURI, $body->getData());
                            }
                        }
                        break;
                }
                $responseType = Constants::RESULT_METHOD;
            } catch (Exception $e) {
                $return = $this->_errorMessage($objectEncoding, $message,
                    $e->getMessage(), $e->getTraceAsString(),$e->getCode(),  $e->getLine());
                $responseType = Constants::STATUS_METHOD;
            }

            $responseURI = $body->getResponseURI() . $responseType;
            $newBody     = new MessageBody($responseURI, null, $return);
            $response->addAmfBody($newBody);
        }
        // Add a session header to the body if session is requested.
        if($this->isSession()) {
           $currentID = session_id();
           $joint = "?";
           if(isset($_SERVER['QUERY_STRING'])) {
               if(!strpos($_SERVER['QUERY_STRING'], $currentID) !== FALSE) {
                   if(strrpos($_SERVER['QUERY_STRING'], "?") !== FALSE) {
                       $joint = "&";
                   }
               }
           }

            // create a new AMF message header with the session id as a variable.
            $sessionValue = $joint . $this->_sessionName . "=" . $currentID;
            $sessionHeader = new MessageHeader(Constants::URL_APPEND_HEADER, false, $sessionValue);
            $response->addAmfHeader($sessionHeader);
        }

        // serialize the response and return serialized body.
        $response->finalize();
    }

    /**
     * Handle an AMF call from the gateway.
     *
     * @param  null|Request $request Optional
     * @return Response
     * @throws AmfException
     */
    public function handle($request = null)
    {
        // Check if request was passed otherwise get it from the server
        if ($request === null || !$request instanceof Request) {
            $request = $this->getRequest();
        } else {
            $this->setRequest($request);
        }
        if ($this->isSession()) {
             // Check if a session is being sent from the amf call
             if (isset($_COOKIE[$this->_sessionName])) {
                 session_id($_COOKIE[$this->_sessionName]);
             }
        }

        // Check for errors that may have happend in deserialization of Request.
        try {
            // Take converted PHP objects and handle service call.
            // Serialize to Response for output stream
            $this->_handle($request);
            $response = $this->getResponse();
        } catch (Exception $e) {
            // Handle any errors in the serialization and service  calls.
            throw new AmfException('Handle error: ' . $e->getMessage() . ' ' . $e->getLine(), 0, $e);
        }

        // Return the Amf serialized output string
        return $response;
    }

    /**
     * Set request object
     *
     * @param  string|Request $request
     * @return Server
     * @throws AmfException
     */
    public function setRequest($request)
    {
        if (is_string($request) && class_exists($request)) {
            $request = new $request();
            if (!$request instanceof Request) {
                throw new AmfException('Invalid request class');
            }
        } elseif (!$request instanceof Request) {
            throw new AmfException('Invalid request object');
        }
        $this->_request = $request;
        return $this;
    }

    /**
     * Return currently registered request object
     *
     * @return null|Request
     */
    public function getRequest()
    {
        if (null === $this->_request) {
            $this->setRequest(new HttpRequest());
        }

        return $this->_request;
    }

    /**
     * Public access method to private Response reference
     *
     * @param  string|Response $response
     * @return Server
     * @throws AmfException
     */
    public function setResponse($response)
    {
        if (is_string($response) && class_exists($response)) {
            $response = new $response();
            if (!$response instanceof Response) {
                throw new AmfException('Invalid response class');
            }
        } elseif (!$response instanceof Response) {
            throw new AmfException('Invalid response object');
        }
        $this->_response = $response;
        return $this;
    }

    /**
     * Get a reference to the Response instance
     *
     * @return Response
     */
    public function getResponse()
    {
        if (null === ($response = $this->_response)) {
            $this->setResponse(new HttpResponse());
        }
        return $this->_response;
    }

    /**
     * Attach a class or object to the server
     *
     * Class may be either a class name or an instantiated object. Reflection
     * is done on the class or object to determine the available public
     * methods, and each is attached to the server as and available method. If
     * a $namespace has been provided, that namespace is used to prefix
     * AMF service call.
     *
     * @param  string|object $class
     * @param  string $namespace Optional
     * @param  mixed $argv Optional arguments to pass to a method
     * @return Server
     * @throws AmfException on invalid input
     * @throws AmfException
     */
    public function setClass($class, $namespace = '', $argv = null)
    {
        if (is_string($class) && !class_exists($class)){
            throw new AmfException('Invalid method or class');
        } elseif (!is_string($class) && !is_object($class)) {
            throw new AmfException('Invalid method or class; must be a classname or object');
        }

        if (2 < func_num_args()) {
            $argv = array_slice(func_get_args(), 2);
        }

        // Use the class name as the name space by default.

        if ($namespace == '') {
            $namespace = is_object($class) ? get_class($class) : $class;
        }

        $this->_classAllowed[is_object($class) ? get_class($class) : $class] = true;

        $this->_methods[] = ReflectorHelper::reflectClass($class, $argv, $namespace);
        $this->_buildDispatchTable();

        return $this;
    }

    /**
     * Attach a function to the server
     *
     * Additional arguments to pass to the function at dispatch may be passed;
     * any arguments following the namespace will be aggregated and passed at
     * dispatch time.
     *
     * @param  string|array $function Valid callback
     * @param  string $namespace Optional namespace prefix
     * @return Server
     * @throws AmfException
     */
    public function addFunction($function, $namespace = '')
    {
        if (!is_string($function) && !is_array($function)) {
            throw new AmfException('Unable to attach function');
        }

        $argv = null;
        if (2 < func_num_args()) {
            $argv = array_slice(func_get_args(), 2);
        }

        $function = (array) $function;
        foreach ($function as $func) {
            if (!is_string($func) || !function_exists($func)) {
                throw new AmfException('Unable to attach function');
            }
            $this->_methods[] = ReflectorHelper::reflectFunction($func, $argv, $namespace);
        }

        $this->_buildDispatchTable();
        return $this;
    }


    /**
     * Creates an array of directories in which services can reside.
     * TODO: add support for prefixes?
     *
     * @param string $dir
     */
    public function addDirectory($dir)
    {
        $this->getLoader()->addPrefixPath("", $dir);
    }

    /**
     * Returns an array of directories that can hold services.
     *
     * @return array
     */
    public function getDirectory()
    {
        return $this->getLoader()->getPaths("");
    }

    /**
     * (Re)Build the dispatch table
     *
     * The dispatch table consists of a an array of method name =>
     * AbstractFunctionReflector pairs
     *
     * @throws AmfException
     */
    protected function _buildDispatchTable()
    {
        $table = [];
        foreach ($this->_methods as $key => $dispatchable) {
            if ($dispatchable instanceof AbstractFunctionReflector) {
                $ns   = $dispatchable->getNamespace();
                $name = $dispatchable->getName();
                $name = empty($ns) ? $name : $ns . '.' . $name;

                if (isset($table[$name])) {
                    throw new AmfException('Duplicate method registered: ' . $name);
                }
                $table[$name] = $dispatchable;
                continue;
            }

            if ($dispatchable instanceof ClassReflector) {
                foreach ($dispatchable->getMethods() as $method) {
                    $ns   = $method->getNamespace();
                    $name = $method->getName();
                    $name = empty($ns) ? $name : $ns . '.' . $name;

                    if (isset($table[$name])) {
                        throw new AmfException('Duplicate method registered: ' . $name);
                    }
                    $table[$name] = $method;
                    continue;
                }
            }
        }
        $this->_table = $table;
    }


    /**
     * Raise a server fault
     *
     * Unimplemented
     *
     * @param  string|Exception $fault
     * @param int $code
     */
    public function fault($fault = null, $code = 404)
    {
    }

    /**
     * Returns a list of registered methods
     *
     * Returns an array of dispatchables (FunctionReflector,
     * _Method, and _Class items).
     *
     * @return array
     */
    public function getFunctions()
    {
        return $this->_table;
    }

    /**
     * Set server persistence
     *
     * Unimplemented
     *
     * @param  mixed $mode
     * @return void
     */
    public function setPersistence($mode)
    {
    }

    /**
     * Load server definition
     *
     * Unimplemented
     *
     * @param  array $definition
     * @return void
     */
    public function loadFunctions($definition)
    {
    }

    /**
     * Map ActionScript classes to PHP classes
     *
     * @param  string $asClass
     * @param  string $phpClass
     * @return Server
     */
    public function setClassMap($asClass, $phpClass)
    {
        TypeLoader::setMapping($asClass, $phpClass);
        return $this;
    }

    /**
     * List all available methods
     *
     * Returns an array of method names.
     *
     * @return array
     */
    public function listMethods()
    {
        return array_keys($this->_table);
    }

    /**
     * Cast parameters
     *
     * Takes the provided parameters from the request, and attempts to cast them
     * to objects, if the prototype defines any as explicit object types
     * 
     * @param  MethodReflector $reflectionMethod 
     * @param  array $params 
     * @return array
     * @updated 2014/05/24: Bui Sy Nguyen <nguyenbs@projectkit.net> modified to support typed array parameter
     */
    protected function _castParameters($reflectionMethod, array $params)
    {
        $prototypes = $reflectionMethod->getPrototypes();
        $nonObjectTypes = array(
            'null',
            'mixed',
            'void',
            'unknown',
            'bool',
            'boolean',
            'number',
            'int',
            'integer',
            'double',
            'float',
            'string',
            'array',
            'object',
            'stdclass',
        );
        $types      = [];
        foreach ($prototypes as $prototype) {
            foreach ($prototype->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (in_array(strtolower($type), $nonObjectTypes)) {
                    continue;
                }
                $position = $parameter->getPosition();
                $types[$position] = $type;
            }
        }

        if (empty($types)) {
            return $params;
        }

        foreach ($params as $position => $value) {
            if (!isset($types[$position])) {
                // No specific type to cast to? done
                continue;
            }

            $type = $types[$position];

            if(substr($type, -2) === '[]') {
                $type = substr($type,0, -2);
                $typedArray = true;
            } else
                $typedArray = false;

            if (!class_exists($type)) {
                // Not a class, apparently. done
                continue;
            }
            if($typedArray) {
                if (!is_array($value)) {
                    continue;
                }
                $items = [];
                foreach ($value as $valueItem) {
                    $items[] = $this->constructParameter($valueItem, $type);
                }
                $params[$position] = $items;
            } else {
                $object = $this->constructParameter($value, $type);
                if(isset($object))
                    $params[$position] = $object;
            }

        }

        return $params;
    }

    /**
     * Construct a parameter value
     * @param mixed $value the value to construct
     * @param string $type the type to construct
     * @return mixed
     * @author Bui Sy Nguyen
     * @created 2013/11/21 to support typed array parameter
     */
    protected function constructParameter($value, $type){
        if ($value instanceof $type) {
            // Already of the right type? done
            return $value;
        }

        if (!is_array($value) && !is_object($value)) {
            // Can't cast scalars to objects easily; done
            return null;
        }

        // Create instance, and loop through value to set
        $object = new $type;
        foreach ($value as $property => $defined) {
            $object->{$property} = $defined;
        }

        return $object;
    }
}
