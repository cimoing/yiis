<?php

namespace app\yiis\web;

use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\HeaderCollection;

class Request extends \yii\web\Request
{
    private $_headers;

    public function getSwooleRequest()
    {
        $context = Coroutine::getContext();
        return $context['request'];
    }

    public function getHeaders()
    {
        if ($this->_headers === null) {
            $this->_headers = new HeaderCollection();
            foreach ($this->swooleRequest->header as $name => $value) {
                $this->_headers->add($name, $value);
            }
        }

        return $this->_headers;
    }

    public function getMethod()
    {
        if (
            isset($this->swooleRequest->post[$this->methodParam])
            && !in_array(strtoupper($this->swooleRequest->post[$this->methodParam]), ['GET', 'HEAD', 'OPTIONS'], true)
        ) {
            return strtoupper($this->swooleRequest->post[$this->methodParam]);
        }

        if ($this->headers->has('X-Http-Method-Override')) {
            return strtoupper($this->headers->get('X-Http-Method-Override'));
        }

        if (isset($this->swooleRequest->server['request_method'])) {
            return strtoupper($this->swooleRequest->server['request_method']);
        }

        return 'GET';
    }

    public function getRawBody()
    {
        return $this->swooleRequest->rawContent();
    }

    private $_bodyParams;

    public function getBodyParams()
    {
        if ($this->_bodyParams === null) {
            if (isset($this->swooleRequest->post[$this->methodParam])) {
                $this->_bodyParams = $this->swooleRequest->post;
                unset($this->_bodyParams[$this->methodParam]);
                return $this->_bodyParams;
            }

            $this->_bodyParams = parent::getBodyParams();
            return $this->_bodyParams;
        }

        return $this->_bodyParams;
    }

    private $_queryParams;

    public function getQueryParams()
    {
        if ($this->_queryParams === null) {
            return $this->swooleRequest->get;
        }

        return $this->_queryParams;
    }

    public function setQueryParams($values)
    {
        $this->_queryParams = $values;
    }

    private $_hostInfo;

    public function getHostInfo()
    {
        if ($this->_hostInfo === null) {
            $secure = $this->getIsSecureConnection();
            $http = $secure ? 'https' : 'http';

            if ($this->getSecureForwardedHeaderTrustedPart('host') !== null) {
                $this->_hostInfo = $http . '://' . $this->getSecureForwardedHeaderTrustedPart('host');
            } elseif ($this->headers->has('X-Forwarded-Host')) {
                $this->_hostInfo = $http . '://' . trim(explode(',', $this->headers->get('X-Forwarded-Host'))[0]);
            } elseif ($this->headers->has('X-Original-Host')) {
                $this->_hostInfo = $http . '://' . trim(explode(',', $this->headers->get('X-Original-Host'))[0]);
            } elseif ($this->headers->has('Host')) {
                $this->_hostInfo = $http . '://' . $this->headers->get('Host');
            } elseif (isset($this->swooleRequest->server['server_name'])) {
                $this->_hostInfo = $http . '://' . $this->swooleRequest->server['server_name'];
                $port = $secure ? $this->getSecurePort() : $this->getPort();
                if (($port !== 80 && !$secure) || ($port !== 443 && $secure)) {
                    $this->_hostInfo .= ':' . $port;
                }
            }
        }

        return $this->_hostInfo;
    }

    private $_scriptUrl;

    public function getScriptUrl()
    {
        if ($this->_scriptUrl === null) {
            return '/index.php';
        }

        return $this->_scriptUrl;
    }

    public function setScriptUrl($value)
    {
        $this->_scriptUrl = $value === null ? null : '/' . trim($value, '/');
    }

    public function getScriptFile()
    {
        return "server.php";
    }

    public function resolvePathInfo()
    {
        $pathInfo = $this->getUrl();

        if (($pos = strpos($pathInfo, '?')) !== false) {
            $pathInfo = substr($pathInfo, 0, $pos);
        }

        $pathInfo = urldecode($pathInfo);

        if (!preg_match('%^(?:
            [\x09\x0A\x0D\x20-\x7E]              # ASCII
            | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
            | \xE0[\xA0-\xBF][\x80-\xBF]         # excluding overlongs
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
            | \xED[\x80-\x9F][\x80-\xBF]         # excluding surrogates
            | \xF0[\x90-\xBF][\x80-\xBF]{2}      # planes 1-3
            | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
            | \xF4[\x80-\x8F][\x80-\xBF]{2}      # plane 16
            )*$%xs', $pathInfo)
        ) {
            $pathInfo = $this->utf8Encode($pathInfo);
        }

        $scriptUrl = $this->getScriptUrl();
        $baseUrl = $this->getBaseUrl();
        if (strpos($pathInfo, $scriptUrl) === 0) {
            $pathInfo = substr($pathInfo, strlen($scriptUrl));
        } elseif ($baseUrl === '' || strpos($pathInfo, $baseUrl) === 0) {
            $pathInfo = substr($pathInfo, strlen($baseUrl));
        } else {
            throw new InvalidConfigException('Unable to determine the path info of the current request.');
        }

        if (strncmp($pathInfo, '/', 1) === 0) {
            $pathInfo = substr($pathInfo, 1);
        }

        return (string) $pathInfo;
    }

    public function resolveRequestUri()
    {
        if ($this->headers->has('X-Rewrite-Url')) { // IIS
            $requestUri = $this->headers->get('X-Rewrite-Url');
        } elseif (isset($this->swooleRequest->server['request_uri'])) {
            $requestUri = $this->swooleRequest->server['request_uri'];
            if ($requestUri !== '' && $requestUri[0] !== '/') {
                $requestUri = preg_replace('/^(http|https):\/\/[^\/]+/i', '', $requestUri);
            }
        } elseif (isset($this->swooleRequest->server['orig_path_info'])) { // IIS 5.0 CGI
            $requestUri = $this->swooleRequest->server['orig_path_info'];
            if (!empty($this->swooleRequest->server['query_string'])) {
                $requestUri .= '?' . $this->swooleRequest->server['query_string'];
            }
        } else {
            throw new InvalidConfigException('Unable to determine the request URI.');
        }

        return $requestUri;
    }

    public function getQueryString()
    {
        return $this->swooleRequest->server['query_string'] ?? '';
    }

    public function getIsSecureConnection()
    {
        if (isset($this->swooleRequest->server['https']) && (strcasecmp($this->swooleRequest->server['https'], 'on') === 0 || $this->swooleRequest->server['https'] == 1)) {
            return true;
        }

        if (($proto = $this->getSecureForwardedHeaderTrustedPart('proto')) !== null) {
            return strcasecmp($proto, 'https') === 0;
        }

        foreach ($this->secureProtocolHeaders as $header => $values) {
            if (($headerValue = $this->headers->get($header, null)) !== null) {
                foreach ($values as $value) {
                    if (strcasecmp($headerValue, $value) === 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function getServerName()
    {
        return $this->swooleRequest->server['server_name'] ?? null;
    }

    public function getServerPort()
    {
        return $this->swooleRequest->server['server_port'] ?? null;
    }

    public function getRemoteIP()
    {
        return $this->swooleRequest->server['remote_addr'] ?? null;
    }

    public function getRemoteHost()
    {
        return $this->swooleRequest->server['remote_host'] ?? null;
    }

    public function getAuthCredentials()
    {
        $username = isset($this->swooleRequest->server['php_auth_user']) ? $this->swooleRequest->server['php_auth_user'] : null;
        $password = isset($this->swooleRequest->server['php_auth_pw']) ? $this->swooleRequest->server['php_auth_pw'] : null;
        if ($username !== null || $password !== null) {
            return [$username, $password];
        }

        /**
         * Apache with php-cgi does not pass HTTP Basic authentication to PHP by default.
         * To make it work, add one of the following lines to to your .htaccess file:
         *
         * SetEnvIf Authorization .+ HTTP_AUTHORIZATION=$0
         * --OR--
         * RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
         */
        $auth_token = $this->getHeaders()->get('Authorization');

        if ($auth_token !== null && strncasecmp($auth_token, 'basic', 5) === 0) {
            $parts = array_map(function ($value) {
                return strlen($value) === 0 ? null : $value;
            }, explode(':', base64_decode(mb_substr($auth_token, 6)), 2));

            if (count($parts) < 2) {
                return [$parts[0], null];
            }

            return $parts;
        }

        return [null, null];
    }

    public function getContentType()
    {
        if (isset($this->swooleRequest->server['content_type'])) {
            return $this->swooleRequest->server['content_type'];
        }

        return $this->headers->get('Content-Type') ?: '';
    }
}