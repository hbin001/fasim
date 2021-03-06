<?php
/**
 * @copyright Copyright(c) 2012 Fasim
 * @author Kevin Lai<lhs168@gmail.com>
 */
namespace Fasim\Core;

use Fasim\Facades\Config as Cfg;
/**
 * @class Router
 * 路由类
 */
class Router {
	private $routers = array();
	private $modules = array();
	private $uriPath = '';
	private $queryArray = array();

	private $defaultModule = '';
	private $defaultController = 'main';
	private $defaultAction = 'default';
	private $matchModule = '';
	private $matchController = '';
	private $matchAction = '';

	private $routerPrifex = '';
	
	public function __construct() {
		$this->init();
		$this->setRouters();
	}
	
	public function getUriPath() {
		return $this->uriPath;
	}
	
	public function getQueryArray() {
		return $this->queryArray;
	}

	public function getMatchModule() {
		return $this->matchModule;
	}

	public function getMatchController() {
		return $this->matchController;
	}

	public function getMatchAction() {
		return $this->matchAction;
	}

	public function getPrifex() {
		return $this->routerPrifex;
	}
	
	public function init() {
		//获取配置
		
		$this->modules = Cfg::get('modules', null);
		if (!is_array($this->modules)) {
			$this->modules = [];
		}
		
		//通过domain获取默认信息
		$domains = Cfg::load('domain');
		$matched = null;
		if (isset($domains['__default__'])) {
			$matched = $domains['__default__'];
			unset($domains['__default__']);
		}
		if (!empty($domains)) {
			$host = $_SERVER['HTTP_HOST'];
			foreach ($domains as $domain => $value) {
				$domain = preg_replace('/![\*\?\:\w\.]/', '', $domain);
				$domain = str_replace('.', '\.', $domain);
				$domain = str_replace('?', '[\w\.]?', $domain);
				$domain = str_replace('*', '[\w\.]*', $domain);
				if (preg_match('/'.$domain.'/i', $host)) {
					$matched = $value;
					break;
				}
			}
		}

		if ($matched) {
			if (isset($matched['module'])) {
				$this->defaultModule = $matched['module'];
				if (isset($value['controller'])) {
					$this->defaultController = $matched['controller'];
					if (isset($value['action'])) {
						$this->defaultAction = $matched['action'];
					}
				}
			}
		}


		$wd = $this->getWebsiteDirectory();
		$requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
		$fixedRequestUri = substr($requestUri, strlen($wd));
		if (strlen($fixedRequestUri) >= 9 && substr($fixedRequestUri, 0, 9) == 'index.php') {
			$fixedRequestUri = substr($fixedRequestUri, strlen($fixedRequestUri) > 9 && $fixedRequestUri{9} == '/' ? 10 : 9);
		}
		
		$uriPath = '';
		$queryString = '';
		if (strpos($fixedRequestUri, '?') !== false) {
			list($uriPath, $queryString) = explode('?', $fixedRequestUri, 2);
		} else if (strpos($fixedRequestUri, '&') !== false) {
			list($uriPath, $queryString) = explode('&', $fixedRequestUri, 2);
		} else {
			$uriPath = $fixedRequestUri;
		}

		//fix url
		while (strpos($uriPath, '//') !== false) {
			$uriPath = str_replace('//', '/', $uriPath);
		}

		while ($uriPath{0} == '/') {
			$uriPath = substr($uriPath, 1);
		}
		
		$routerPrifex = Cfg::get('router_prifex', '');
		if ($routerPrifex != null && $uriPath != '') {
			//$this->routerPrifex
			$matches = [];
			preg_match('/^'.$routerPrifex.'/', $uriPath, $matches);
			if (count($matches) > 0) {
				$uriPath = substr($uriPath, strlen($matches[0]));
				$this->routerPrifex = '/' . $matches[0];
			}
		}

		while (substr($uriPath, -1) == '/') {
			$uriPath = substr($uriPath, 0, -1);
		}

		$this->uriPath = '/'.$uriPath;
		
		if ($uriPath) {
			$pathArray = explode('/', $uriPath);
			while (count($pathArray) > 0) {
				array_push($this->queryArray, array_shift($pathArray));
			}
		}

		if ($queryString) {
			$this->parseUrlQuery($queryString);
		}
	
	}

	protected function parseUrlQuery($queryString) {
		$qsa = explode('&', $queryString);
		foreach ($qsa as $qs) {
			if ($qs === '') continue;
			list($qsk, $qsv) = strpos($qs, '=') === false ? array($qs, '') : explode('=', $qs, 2);
			$this->queryArray[$qsk] = urldecode($qsv);
		}
	}

	public function setRouters() {
		$this->routers = [];
		$routers = Cfg::load('router');
		$this->addRouters($routers);
	}

	public function addRouters($routers) {

		foreach ($routers as $uri => $caq) {
			if (empty($caq)) {
				continue;
			}
			$ca = $caq;
			$q = null;
			if (strpos($caq, '?') !== false) {
				list($ca, $q) = explode('?', $caq);
			}
			if ($ca{0} == '/') {
				$ca = substr($ca, 1);
			}

			$p = explode('/', $ca);
			while (count($p) < 3) {
				$p[] = '';
			}
			list($c, $a) = $p;

			$m = '';
			if (in_array($c, $this->modules)) {
				list($m, $c, $a) = $p;
			}

			$this->routers[] = array(
				'uri' => $uri,
				'module' => $m,
				'controller' => $c,
				'action' => $a,
				'query' => $q
			);
		}
	}


	public function dispatch() {
		$uri = $this->uriPath;
		$matched = false;

		if ($this->routers) {
			foreach($this->routers as $router) {
				$matchUri = $router['uri'];
				if ($matchUri == $uri || $matchUri == $uri.'/' || $this->checkMatchRouterEvent($matchUri, $uri) || $this->checkMatchRouterEvent($matchUri, $uri.'/')) {
					if (!empty($router['module'])) {
						$this->matchModule = $router['module'];
					}
					$this->matchController = $router['controller'];
					$this->matchAction = $router['action'];
					//query
					$this->parseUrlQuery($router['query']);
					$matched = true;
					break;
				}
			}
		}
		if (!$matched) {
			$components = explode('/', $uri);
			array_shift($components);
			if (count($components) > 0) {
				$component = array_shift($components);
				if ($this->matchModule == '' && in_array($component, $this->modules)) {
					$this->matchModule = $component;
					if (count($components) > 0) {
						$this->matchController = array_shift($components);
					}
				} else {
					$this->matchController = $component;
				}
			}
			if (count($components) > 0) {
				$this->matchAction = array_shift($components);
			}
		}
		if ($this->matchModule == '') {
			$this->matchModule = $this->defaultModule;
		}
		if ($this->matchController == '') {
			$this->matchController = $this->defaultController;
		}
		if ($this->matchAction == '') {
			$this->matchAction = $this->defaultAction;
		}
		
		
	}

	function checkMatchRouterEvent($type, $uri) {
		//允许*号匹配，%d, %f, %s
		$pattern = str_replace(array(
			 '\\', '/', '.', '(', ')', '{', '}', '^', '$', '+', '?', '&', '*', '%s', '%d', '%f'
		), array(
			 '\\\\', '\/', '\.', '\(', '\)', '\{', '\}', '\^', '\$', '\+', '\?', '\&', '(.*?)', '(.*?)', '(\d*?)', '([\.\d]*?)'
		), $type);
		return preg_match('/^'.$pattern.'$/is', $uri);
	}

	public function getWebsiteDirectory() {
		$wd = '/';
		if (isset($_SERVER['DOCUMENT_ROOT']) && isset($_SERVER['SCRIPT_FILENAME'])) {
			$scriptDir = dirname($_SERVER['SCRIPT_FILENAME']) . '/';
			$wd = substr($scriptDir, strlen($_SERVER['DOCUMENT_ROOT']));
		}
		return $wd;
	}
	
	/**
	 * 组装url
	 * @param string $path 如gh：
	 * http://test.com/controller/action?param1=value1&param2=value2
	 * http://test.com/index.php/controller/action?param1=value1&param2=value2
	 * http://test.com/?e=/controller/action&param1=value1&param2=value2
	 */
	public function makeUrl($path) {
		if ($path{0} == '/') $path = substr($path, 1);
		$dsn = explode('/', $path, 2);
		$size = count($dsn);
		
		$controller = $dsn[0];
		$action = '';
		if ($size > 0) {
			$action = $dsn[1];
		}
		
		$param_array = array();
		if ($size > 1) {
			for ($i = 2; $i <= $size; $i+=2) {
				$param_array[$names[$size]] = isset($names[$size + 1]) ? $names[$size + 1] : '';
			}
		}
		
		$url = $controller;
		if ($action != '') {
			$url.= '/' . $action;
		}
		
	}
}


