<?php
namespace Cache\Routing\Filter;

use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Routing\DispatcherFilter;
use Cake\Utility\Inflector;

use Cake\Cache\Cache;

/**
 * @deprecated Use new 3.3+ CacheMiddleware instead.
 */
class CacheFilter extends DispatcherFilter {

	/**
	 * Default priority for all methods in this filter
	 * This filter should run before the request gets parsed by router
	 *
	 * @var int
	 */
	protected $_priority = 9;

	/**
	 * The amount of time to browser cache files (which are unlimited).
	 *
	 * @var string
	 */
	protected $_cacheTime = '+1 day';

	/**
	 * @var string|null
	 */
	protected $_cacheContent;

	/**
	 * @var array|null
	 */
	protected $_cacheInfo;

	/**
	 * @param array $config Array of config.
	 */
	public function __construct($config = []) {
		if (!empty($config['cacheTime'])) {
			$this->_cacheTime = $config['cacheTime'];
		}
		parent::__construct($config);
	}

	/**
	 * Checks if a requested cache file exists and sends it to the browser
	 *
	 * @param \Cake\Event\Event $event containing the request and response object
	 *
	 * @return \Cake\Network\Response|null Response if the client is requesting a recognized cache file, null otherwise
	 */
	public function beforeDispatch(Event $event) {
		if (Configure::read('Cache.check') === false) {
			return null;
		}

		/* @var \Cake\Network\Request $request */
		$request = $event->data['request'];

		$url = $request->here();
		$url = str_replace($request->base, '', $url);
    $file = $this->getFile($url);
		/*
		if ($file === null) {
			return null;
		}
		
		$cacheContent = $this->extractCacheContent($file);
		*/
		
    $cacheEngine='_cake_views_';
    $cacheContent = Cache::read($file, $cacheEngine);
    if(!$cacheContent) {
      return null;
    }
		$this->_cacheContent = $cacheContent;
		
		$cacheInfo = $this->extractCacheInfo($cacheContent);
		$cacheCreated = $cacheInfo['created'];
		$cacheExpire = $cacheInfo['expire'];
		$ext = $cacheInfo['ext'];
    
		if ($cacheExpire < time() && $cacheExpire != 0) {
			//unlink($file);
			Cache::delete($file);
			return null;
		}

		/* @var \Cake\Network\Response $response */
		$response = $event->data['response'];
		$event->stopPropagation();

		//$response->modified(filemtime($file));
		$response->modified($cacheCreated);
		if ($response->checkNotModified($request)) {
			return $response;
		}

/*
		$pathSegments = explode('.', $file);
		$ext = array_pop($pathSegments);
		$this->_deliverCacheContent($request, $response, $file, $ext);
*/
		$size = strlen($cacheContent);
		$this->_deliverCacheContent($request, $response, $size);
		return $response;
	}

	/**
	 * @param string $url
	 * @param bool $mustExist
	 *
	 * @return string
	 */
	public function getFile($url, $mustExist = true) {
		if ($url === '/') {
			$url = '_root';
		}

		$path = $url;
		$prefix = Configure::read('Cache.prefix');
		if ($prefix) {
			$path = $prefix . '_' . $path;
		}

		if ($url !== '_root') {
			$path = Inflector::slug($path);
		}

    /*
		$folder = CACHE . 'views' . DS;
		$file = $folder . $path . '.html';
		
		if ($mustExist && !file_exists($file)) {
			return null;
		}
		return $file;
		*/
		$file = 'views_'.md5($path);
		return $file;
	}

	/**
	 * @param string &$content
	 *
	 * @return array Time/Ext
	 */
	public function extractCacheInfo(&$content) {
		if ($this->_cacheInfo) {
			return $this->_cacheInfo;
		}

		$cacheCreated = 0;
		$cacheExpire = 0;
		$cacheExt = 'html';
		$this->_cacheContent = preg_replace_callback('/^\<\!--created\:(\d+);expire\:(\d+);ext\:(\w+)--\>/', function ($matches) use (&$cacheCreated, &$cacheExpire, &$cacheExt) {
  		$cacheCreated = $matches[1];
  		$cacheExpire = $matches[2];
			$cacheExt = $matches[3];
			return '';
		}, $this->_cacheContent);

		$this->_cacheInfo = [
			'created' => (int)$cacheCreated,
			'expire' => (int)$cacheExpire,
			'ext' => $cacheExt
		];

		return $this->_cacheInfo;
	}

	/**
	 * @param string $file
	 *
	 * @return string
	 */
	protected function extractCacheContent($file) {
		if ($this->_cacheContent !== null) {
			return $this->_cacheContent;
		}

		$this->_cacheContent = (string)file_get_contents($file);

		return $this->_cacheContent;
	}

	/**
	 * Sends an asset file to the client
	 *
	 * @param \Cake\Network\Request $request The request object to use.
	 * @param \Cake\Network\Response $response The response object to use.
	 * @param string $file Path to the asset file in the file system
	 * @param string $ext The extension of the file to determine its mime type
	 *
	 * @return void
	 */
	protected function _deliverCacheFile(Request $request, Response $response, $file, $ext) {
		$compressionEnabled = $response->compress();
		if ($response->type($ext) === $ext) {
			$contentType = 'application/octet-stream';
			$agent = $request->env('HTTP_USER_AGENT');
			if (preg_match('%Opera(/| )([0-9].[0-9]{1,2})%', $agent) || preg_match('/MSIE ([0-9].[0-9]{1,2})/', $agent)) {
				$contentType = 'application/octetstream';
			}
			$response->type($contentType);
		}
		if (!$compressionEnabled) {
			$response->header('Content-Length', filesize($file));
		}

		$cacheContent = $this->_cacheContent;
		$cacheInfo = $this->_cacheInfo;

		$modifiedTime = filemtime($file);
		$cacheTime = $cacheInfo['time'];
		if (!$cacheTime) {
			$cacheTime = $this->_cacheTime;
		}
		$response->cache($modifiedTime, $cacheTime);
		$response->type($cacheInfo['ext']);

		if (Configure::read('debug') || $this->config('debug')) {
			if ($cacheInfo['ext'] === 'html') {
				$cacheContent = '<!--created:' . date('Y-m-d H:i:s', $modifiedTime) . '-->' . $cacheContent;
			}
		}
		$response->body($cacheContent);
	}

	/**
	 * Sends an asset file to the client
	 *
	 * @param \Cake\Network\Request $request The request object to use.
	 * @param \Cake\Network\Response $response The response object to use.
	 * @param int $size Size of the file
	 *
	 * @return void
	 */
	protected function _deliverCacheContent(Request $request, Response $response, $size) {
		$cacheContent = $this->_cacheContent;
		$cacheInfo = $this->_cacheInfo;
		$compressionEnabled = $response->compress();
		if ($response->type($cacheInfo['ext']) === $cacheInfo['ext']) {
			$contentType = 'application/octet-stream';
			$agent = $request->env('HTTP_USER_AGENT');
			if (preg_match('%Opera(/| )([0-9].[0-9]{1,2})%', $agent) || preg_match('/MSIE ([0-9].[0-9]{1,2})/', $agent)) {
				$contentType = 'application/octetstream';
			}
			$response->type($contentType);
		}
		if (!$compressionEnabled) {
			$response->header('Content-Length', $size);
		}

		$createdTime = $cacheInfo['created'];
		$cacheTime = $cacheInfo['expire'];
		if (!$cacheTime) {
			$cacheTime = $this->_cacheTime;
		}
		$response->cache($createdTime, $cacheTime);
		$response->type($cacheInfo['ext']);

		if (Configure::read('debug') || $this->config('debug')) {
			if ($cacheInfo['ext'] === 'html') {
				$cacheContent = '<!--created:' . date('Y-m-d H:i:s', $modifiedTime) . '-->' . $cacheContent;
			}
		}
		$response->body($cacheContent);
	}

}
