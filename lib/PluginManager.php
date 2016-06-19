<?php

//Aimed to manage plugins and add them to the existing project
class PluginManager {
  //Constant to specify where to place hook handler
  //@see setHook $hookPosition
  const HOOK_ON_BEFORE_CALL = 'before';

  //Constant to specify where to place hook handler
  //@see setHook $hookPosition
  const HOOK_ON_AFTER_CALL = 'after';

  //For Singleton implementation
  private static $_instance = null;

  //A set of plugins extending Plugin_Base
  private $_plugins = array();

  //This is the prioritized queue of hook handlers.
  private $_hookQueue = array();

  //Classes list PluginManager should enable hooks support for
  private $_wrapClasses = array();

  //Specifies if type check is active
  private $_typeCheck = true;
  
  //Plugins call stack
  private $_callStack = array();

  //Indicates if plugin is in action
  private $_inPlugin = false;
  
  //For singleton pattern
  private function __construct()
  {
  }

  //For Singleton pattern
  private function __clone()
  {
  }

  //Returns current instance
  public static function getInstance()
  {
    if (is_null(self::$_instance)) {
    	self::$_instance = new self;
    }
    return self::$_instance;
  }

  //Requires a class file and wraps it for hooks support if necessary. Returns false if requested file doesn't need to be wrapped
  public function wrapClass($className, $fileName)
  {
    if (!in_array($className, $this->_wrapClasses)) {
        return false;
    }
    
    $path = false;
    foreach (explode(PATH_SEPARATOR, get_include_path()) as $iPath) {
        if (!file_exists($iPath.DIRECTORY_SEPARATOR.$fileName)) {
    	    continue;
        }
        $path = $iPath.DIRECTORY_SEPARATOR.$fileName;
        break;
    }
    if ($path === false) {
        return false;
    }
    
    $class = file_get_contents($path);
    $class = preg_replace('|\s/\*\*(?:.*?)\*/|s', '', $class);
    if (!preg_match('/\s?class (\w+)[\s$]/', $class, $matches)) {
        return false;
    }
    $name = trim($matches[1]);
    
    $newClass = str_replace('<?php', '', $class);
    $newClass = str_replace('<?', '', $newClass);
    $newClass = str_replace('?>', '', $newClass);
    $newClass = preg_replace('|//(.*)\n|', '', $newClass);
    preg_match_all('/(abstract)?\s*(private|public|protected)\s?(static)?\s+function\s+(\w+)\s?\((.*?)\)/', $newClass, $methods);

    foreach ($methods[4] as $key => $method) {
        if ((strpos($method, '__') === 0)||(!empty($methods[1][$key]))) {
    	    continue;
        }
        $f = $methods[2][$key].' '.$methods[3][$key].' function '.$method.'('.$methods[5][$key].') {
    $args = func_get_args();
    $args = '.__CLASS__.'::getInstance()->hookCallback('.__CLASS__.'::HOOK_ON_BEFORE_CALL, '.(($methods[3][$key] == 'static') ? '__CLASS__' : '$this').', "'.$method.'", $args);
    $return = call_user_func_array(array('.(($methods[3][$key] == 'static') ? '__CLASS__' : '$this').', "'.$method.'_Hooked"), $args);
    $return = '.__CLASS__.'::getInstance()->hookCallback('.__CLASS__.'::HOOK_ON_AFTER_CALL, '.(($methods[3][$key] == 'static') ? '__CLASS__' : '$this').', "'.$method.'", $return);
    return $return;
    }
    ';
        $newClass = preg_replace('/'.$methods[2][$key].'\s+'.(($methods[3][$key] == 'static') ? $methods[3][$key].'\s+' : '').'function\s+'.$method.'\s?\(/', $f."\n".$methods[2][$key].' '.$methods[3][$key].' function '.$method.'_Hooked (', $newClass);
    }
    
    $sensorApiText = '
    
    public function getSensorData($sensorName) {
    
        if (isset($this->$sensorName)) {
    	    return $this->$sensorName;
        }
        return null;
    }
    
    public static function getStaticSensorData($sensorName) {
    
        if (isset(self::$$sensorName)) {
    	    return self::$$sensorName;
        }
        return null;
    }
    
    ';
    
    $newClass = preg_replace('/(\s?class\s+'.$name.'.*?\{)(.*)/s', '\1 '.$sensorApiText.' \2', $newClass);
    
    try {
        eval($newClass);
        return true;
    } catch (Exception $e) {
        error_log('Class '.$name.' found but cannot be hooked.');
        return false;
    }
  }

  //Loads requested plugin and initializes it
  public function loadPlugin($pluginClassName)
  {
    $instance = new $pluginClassName;
    		
    if (!$instance instanceof PluginBase) {
    	error_log('Requested plugin `'.$pluginClassName.'` load but this plugin is not an instance of PluginBase');
    	return;
    }
    
    $this->_plugins[] = $instance;
    $instance->setStartupHooks();
    $instance->setEventHooks();
  }

  //Sets the hook to the specified class and method with call conditions
  public function setHook($handlerObject, $handlerMethod, $hookPosition, $watchClassName, $watchMethodName, $priority = 0, $callerClassName = null, $callerMethodName = null)
  {
    if (!$handlerObject instanceof PluginBase) {
    	error_log('Call to '.__CLASS__.'::'.__METHOD__.' from a context different from PluginBase');
    	return;
    }
      
    if (!isset($this->_hookQueue[$priority])) {
    	$this->_hookQueue[$priority] = array();
    }
    
    $queueRecord = array (
    	$hookPosition => array(
    		$watchClassName => array(
    			$watchMethodName => array (
    			'callObject' => array($handlerObject),
    			'callMethod' => $handlerMethod,
    			'caller' => $callerClassName,
    			'callerMethod' => $callerMethodName
    			)
    		)
    	)
    );
    
    $this->_hookQueue[$priority] = array_merge_recursive($this->_hookQueue[$priority], $queueRecord);
    
    //Setting class as requred for hooks support
    $this->_wrapClasses[] = $watchClassName;
  }

  //return maximum priority in current hook queue
  public function getMaxPriority()
  {
    return (empty($this->_hookQueue)) ? 0 : max(array_keys($this->_hookQueue));
  }

  //This method is called by wrapped class each time code reaches hook placeholder
  public function hookCallback($hookPlace, $object, $methodName, $parameters)
  {
    //We have to return at least source params
    $result = $parameters;
    
    $priority = $this->getMaxPriority();
    $className = (is_object($object) ? get_class($object) : $object);
    //We have to pass static object to plugin, so Let's do it
    if (!is_object($object)) {
        $object = new PluginTransport($className);
    }
    //Applying handlers according to priority
    for ($i=$priority; $i>=0; $i--) {
        if (!isset($this->_hookQueue[$i][$hookPlace][$className][$methodName])) {
    	    $isChild = false;
    	    //We should check if the hook is set for parent class
    	    if (isset($this->_hookQueue[$i][$hookPlace]) && is_object($object)) {
    		    foreach ($this->_hookQueue[$i][$hookPlace] as $cname => $val) {
    			    $isInstance = ($object instanceof PluginTransport) ? $object->isInstanceOf($cname) : $object instanceof $cname;
    			    if ($isInstance) {
    				    if (isset($this->_hookQueue[$i][$hookPlace][$cname][$methodName])) {
    					    $className = $cname;
    					    $isChild = true;
    					    break;
    				    }
    			    }
    		    }
    	    }
    	    if (!$isChild) {
    		    continue;
    	    }
        }
    
        $handlers =& $this->_hookQueue[$i][$hookPlace][$className][$methodName];
    
        if (!is_array($handlers['callMethod'])) {
    	    $handlers['callMethod'] = array($handlers['callMethod']);
        }
        if (!is_array($handlers['caller'])) {
    	    $handlers['caller'] = array($handlers['caller']);
        }
        if (!is_array($handlers['callerMethod'])) {
    	    $handlers['callerMethod'] = array($handlers['callerMethod']);
        }
        foreach ($handlers['callMethod'] as $key => $method) {
    	    $okToCall = true;
    	    if (!is_null($handlers['caller'][$key])) {
    		    $okToCall = false;
    		    $backtrace = debug_backtrace();
    		    foreach ($backtrace as $item) {
    			    if (!isset($item['class'])) {
    				    continue;
    			    }
    			    if (isset($handlers['caller'][$key]) && ($item['class'] == $handlers['caller'][$key])) {
    				    if (!isset($handlers['callerMethod'][$key]) || is_null($handlers['callerMethod'][$key]) || ($item['function'] == $handlers['callerMethod'][$key])) {
    					    $okToCall = true;
    					    break;
    				    }
    			    }
    		    }
    	    }
    	    if ($okToCall) {
    		    $handlerObject = $handlers['callObject'][$key];
    		    $this->_typeCheck = true;
    		    //Checking call stack
    		    $call = get_class($handlerObject).'::'.$method;
    		    $emergency = false;
    		    if (!$this->_inPlugin && in_array($call, $this->_callStack)) {
    		    	//Possible recursion
    		    	$msg = __CLASS__.' detected possible recursion call to '.$call.'. Probably handler uses hooked object and raised another call to the same hook. To avoid this, please specify the object/method you expect event from. You can do it in setEventHooks or setStartupHooks plugin methods.';
    		    	error_log('WARNING! '.$msg);
    		    	$emergency = true;
    		    } 
    		    
    		    //If we have plugin already in action, we don't call handlers. We don't call handlers in case of emergency as well
    		    if ($this->_inPlugin || $emergency) {
    		    	return call_user_func_array(array($object, $methodName.'_Hooked'), $parameters);
    		    }
    		    
    		    if (empty($this->_callStack)) {
    		    	$this->_inPlugin = true;
    		    }
    		    array_push($this->_callStack, $call);
    		    $result = call_user_func_array(array($handlerObject, $method), array($object, $result));
    		    array_pop($this->_callStack);
    		    
    		    if (empty($this->_callStack)) {
    		    	$this->_inPlugin = false;
    		    }
    		    
    		    if ($this->_typeCheck && (gettype($parameters) != gettype($result))) {
    			    error_log(__METHOD__.': Type check rejected '.get_class($handlerObject).'::'.$method.' return value. (Original type: '.gettype($parameters).', Returned type: '.gettype($result).')');
    			    $result = $parameters;
    		    }
    		      
    	    }
        }
    }
    return $result;
  }

  //Disable return value type check for current handler
  public function disableTypeCheck()
  {
    $this->_typeCheck = false;
  }
  
  //Enables mode when plugin can call object method and rais another hook. This is disabled by default
  public function enableMultiHooks() {
  	$this->_inPlugin = false;
  }
  

}
