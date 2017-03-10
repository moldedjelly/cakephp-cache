<?php
namespace Cache\Routing\Middleware;

use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Utility\Inflector;

use Cake\Cache\Cache;

/**
 * Note that this middleware is only expected to work for CakePHP 3.4+
 */
class CacheMiddleware {

	use InstanceConfigTrait;

	/**
	 * @var array
	 */
	protected $_defaultConfig = [
		'when' => null,
		'cacheTime' => '+1 day',
		//TODO 'priority' => 9,
	];

	/**
	 * @var string|null
	 */
	protected $_cacheContent;

	/**
	 * @var array|null
	 */
	protected $_cacheInfo;

	/**
	 * @param array $config
	 */
	public function __construct(array $config = []) {
		$this->config($config);
	}

	/**
	 * Checks if a requested cache file exists and sends it to the browser.
	 *
	 * @param \Psr\Http\Message\ServerRequestInterface $request The request.
	 * @param \Psr\Http\Message\ResponseInterface $response The response.
	 * @param callable $next The next middleware to call.
	 * @return \Psr\Http\Message\ResponseInterface A response.
	 */
	public function __invoke(Request $request, Response $response, $next) {
		if (Configure::read('Cache.check') === false) {
			return $next($request, $response);
		}
		/* @var callable $when */
		$when = $this->config('when');
		if ($when !== null && $when($request, $request) !== true) {
			return $next($request, $response);
		}

		/* @var \Cake\Http\ServerRequest $request */
		$url = $request->here();
		$url = str_replace($request->base, '', $url);
		$file = $this->getFile($url);

/*
		if ($file === null) {
			return $next($request, $response);
		}
		$cacheContent = $this->extractCacheContent($file);
*/

    $cacheEngine='_cake_views_';
    $cacheContent = Cache::read($file, $cacheEngine);
    if(!$cacheContent) {
      return $next($request, $response);
    }
		$this->_cacheContent = $cacheContent;
		
		$cacheInfo = $this->extractCacheInfo($cacheContent);
		$cacheCreated = $cacheInfo['created'];
		$cacheExpire = $cacheInfo['expire'];
		$ext = $cacheInfo['ext'];

		if ($cacheExpire < time() && $cacheExpire !== 0) {
			//unlink($file);
			Cache::delete($file);
			return $next($request, $response);
		}

		/* @var \Cake\Http\Response $response */
/*
		$response = $response->withModified(filemtime($file));
		if ($response->checkNotModified($request)) {
			return $response;
		}
*/
		$response = $response->withModified($cacheCreated);
		if ($response->checkNotModified($request)) {
			return $response;
		}

/*
		$pathSegments = explode('.', $file);
		$ext = array_pop($pathSegments);
		$response = $this->_deliverCacheFile($request, $response, $file, $ext);
*/
    $size = strlen($cacheContent);
		$response = $this->_deliverCacheContent($request, $response, $size);

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
	 * @param \Cake\Http\ServerRequest $request The request object to use.
	 * @param \Cake\Http\Response $response The response object to use.
	 * @param string $file Path to the asset file in the file system
	 * @param string $ext The extension of the file to determine its mime type
	 *
	 * @return \Cake\Network\Response
	 */
	protected function _deliverCacheFile(Request $request, Response $response, $file, $ext) {
		$compressionEnabled = $response->compress();
		if ($response->type() === $ext) {
			$contentType = 'application/octet-stream';
			$agent = $request->env('HTTP_USER_AGENT');
			if (preg_match('%Opera(/| )([0-9].[0-9]{1,2})%', $agent) || preg_match('/MSIE ([0-9].[0-9]{1,2})/', $agent)) {
				$contentType = 'application/octetstream';
			}

			$response = $response->withType($contentType);
		}

		if (!$compressionEnabled) {
			$response = $response->withHeader('Content-Length', (string)filesize($file));
		}

		$cacheContent = $this->_cacheContent;
		$cacheInfo = $this->_cacheInfo;

		$modifiedTime = filemtime($file);
		$cacheTime = $cacheInfo['time'];
		if (!$cacheTime) {
			$cacheTime = $this->config('cacheTime');
		}

		$response = $response->withCache($modifiedTime, $cacheTime);
		$response = $response->withType($cacheInfo['ext']);

		if (Configure::read('debug') || $this->config('debug')) {
			if ($cacheInfo['ext'] === 'html') {
				$cacheContent = '<!--created:' . date('Y-m-d H:i:s', $modifiedTime) . '-->' . $cacheContent;
			}
		}

		$body = $response->getBody();
		$body->write($cacheContent);
		return $response->withBody($body);
	}
	
	
	/**
	 * Sends an asset file to the client
	 *
	 * @param \Cake\Http\ServerRequest $request The request object to use.
	 * @param \Cake\Http\Response $response The response object to use.
	 * @param int $size Size of the file
	 *
	 * @return \Cake\Network\Response
	 */
	protected function _deliverCacheContent(Request $request, Response $response, $size) {
		$compressionEnabled = $response->compress();
		$cacheContent = $this->_cacheContent;
		$cacheInfo = $this->_cacheInfo;
		if ($response->type() === 'html') {
			$contentType = 'application/octet-stream';
			$agent = $request->env('HTTP_USER_AGENT');
			if (preg_match('%Opera(/| )([0-9].[0-9]{1,2})%', $agent) || preg_match('/MSIE ([0-9].[0-9]{1,2})/', $agent)) {
				$contentType = 'application/octetstream';
			}
			$response = $response->withType($contentType);
		}

		if (!$compressionEnabled) {
			$response = $response->withHeader('Content-Length', (string)$size);
		}

		$modifiedTime = $cacheInfo['created'];
		$cacheTime = $cacheInfo['expire'];
		if (!$cacheTime) {
			$cacheTime = $this->config('cacheTime');
		}

		$response = $response->withCache($modifiedTime, $cacheTime);
		$response = $response->withType($cacheInfo['ext']);

		if (Configure::read('debug') || $this->config('debug')) {
			if ($cacheInfo['ext'] === 'html') {
				$cacheContent = '<!--created:' . date('Y-m-d H:i:s', $modifiedTime) . '-->' . $cacheContent;
			}
		}

		$body = $response->getBody();
		$body->write($cacheContent);
		return $response->withBody($body);
	}

}
