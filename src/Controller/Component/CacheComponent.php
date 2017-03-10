<?php
namespace Cache\Controller\Component;

use Cache\Utility\Compressor;
use Cake\Controller\Component;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Utility\Inflector;

use Cake\Cache\Cache;

/**
 */
class CacheComponent extends Component {

	/**
	 * Default config
	 *
	 * @var array
	 */
	protected $_defaultConfig = [
		'duration' => null,
		'actions' => [],
		'compress' => false,
	];

	/**
	 * @param \Cake\Event\Event $event
	 * @return void
	 */
	public function shutdown(Event $event) {
		$this->response = $event->subject->response;

		$content = $this->response->body();
		if (!$content) {
			return;
		}

		$duration = $this->config('duration');
		$isActionCachable = $this->_isActionCachable();
		if ($isActionCachable === false) {
			return;
		}
		if ($isActionCachable !== true) {
			$duration = $isActionCachable;
		}

		$this->_writeFile($content, $duration);
	}

	/**
	 * @return bool|int|string
	 */
	protected function _isActionCachable() {
		$actions = $this->config('actions');
		if (!$actions) {
			return true;
		}

		$action = $this->request->params['action'];

		if (array_key_exists($action, $actions)) {
			return $actions[$action];
		}
		if (in_array($action, $actions, true)) {
			return true;
		}
		return false;
	}

	/**
	 * Write a cached version of the file
	 *
	 * @param string $content view content to write to a cache file.
	 * @param int|string $duration Duration to set for cache file.
	 * @return bool Success of caching view.
	 */
	protected function _writeFile($content, $duration) {
		//$cacheTime = date('Y-m-d H:i:s', $timestamp);
		$now = time();
		if (!$duration) {
			$cacheExpire = 0;
		} elseif (is_numeric($duration)) {
			$cacheExpire = $now + $duration;
		} else {
			$cacheExpire = strtotime($duration, $now);
		}
		$cacheCreated = $now;

		$url = $this->request->here();
		$url = str_replace($this->request->base, '', $url);
		if ($url === '/') {
			$url = '_root';
		}

		$cache = $url;
/*
		$prefix = Configure::read('Cache.prefix');
		if ($prefix) {
			$cache = $prefix . '_' . $url;
		}
*/
		if ($url !== '_root') {
			$cache = Inflector::slug($cache);
		}
		if (empty($cache)) {
			return false;
		}

		$ext = $this->response->mapType($this->response->type());
		$content = $this->_compress($content, $ext);

		//$cache = $cache . '.html';
		$cache = $cache;
		$content = '<!--created:' . $cacheCreated . ';expire:' . $cacheExpire . ';ext:' . $ext . '-->' . $content;
    
    /*
		$folder = CACHE . 'views' . DS;
		if (Configure::read('debug') && !is_dir($folder)) {
			mkdir($folder, 0770, true);
		}
		$file = $folder . $cache;

		return file_put_contents($file, $content);
		*/
		
    $cacheEngine='_cake_views_';
    $cacheKey = 'views_'.md5($cache);
    if(!Cache::write($cacheKey, $content, $cacheEngine)) {
      return false;
    }
    return true;
		
	}

	/**
	 * Compresses HTML
	 *
	 * @param string $content
	 * @param string $ext
	 * @return string Content
	 */
	protected function _compress($content, $ext) {
		$compress = $this->config('compress');
		if ($compress === true) {
			// Native compressor only supports HTML right now
			if ($ext === 'html') {
				$Compressor = new Compressor();
				$content = $Compressor->compress($content);
			}
		} elseif (is_callable($compress)) {
			$content = $compress($content, $ext);
		} elseif ($compress) {
			$content = call_user_func($compress, $content, $ext);
		}

		return $content;
	}

}
