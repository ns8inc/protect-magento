<?php
namespace NS8\CSP2\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\HTTP\ZendClientFactory;
use Psr\Log\LoggerInterface;
use Zend\Http\Client;
use Zend\Json\Decoder;

use NS8\CSP2\Helper\Config;

/**
 * General purpose HTTP/REST client for making API calls
 */
class HttpClient extends AbstractHelper
{
    protected $config;
    protected $logger;

    /**
     * Default constructor
     *
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Makes an HTTP GET request
     *
     * @param string $url URL to target.
     * @param mixed $data Data to include in the request body.
     * @param array $parameters Optional array of request parameters.
     * @param array $headers Optional array of request headers.
     * @param integer $timeout Optional timeout value. Default 30.
     * @return mixed the XHR reponse object.
     */
    public function get($url, $data, $parameters = [], $headers = [], $timeout = 30)
    {
        return $this->execute($url, $data, "GET", $parameters, $headers, $timeout);
    }

    /**
     * Makes an HTTP POST request
     *
     * @param string $url URL to target.
     * @param mixed $data Data to include in the request body.
     * @param array $parameters Optional array of request parameters.
     * @param array $headers Optional array of request headers.
     * @param integer $timeout Optional timeout value. Default 30.
     * @return mixed the XHR reponse object.
     */
    public function post($url, $data, $parameters = [], $headers = [], $timeout = 30)
    {
        return $this->execute($url, $data, "POST", $parameters, $headers, $timeout);
    }

    /**
     * Internal method to handle the logic of making the HTTP request
     *
     * @param [type] $url
     * @param [type] $data
     * @param string $method
     * @param array $parameters
     * @param array $headers
     * @param integer $timeout
     * @return mixed the XHR reponse object.
     */
    private function execute($url, $data, $method = "POST", $parameters = [], $headers = [], $timeout = 30)
    {
        try {
            $uri = $this->config->getApiBaseUrl().$url.'?Authorization='.$this->config->getAccessToken();
            $httpClient = new Client();
            $httpClient->setUri($uri);
            #TODO: support the parameters/headers passed in
            $httpClient->setOptions(array('timeout' => $timeout));
            $httpClient->setMethod($method);

            #TODO: make this more robust; nothing everything can be converted to JSON
            $json = json_encode($data);
            #TODO: this is a KLUDGE. There must be a better way!
            $httpClient->setRawBody($json);

            if (!empty($parameters)) {
                    switch($method) {
                    case 'GET':
                        $httpClient->setParameterGet($parameters);
                        break;
                    default:
                        $httpClient->setParameterPost($parameters);
                        break;
                }
                //$httpClient->setHeaders($headers);
            }
            #TODO: decompose this into more discrete steps.
            $response = Decoder::decode($httpClient->send()->getBody());
        } catch (\Exception $e) {
            $this->logger->error('Failed to execute API call', $e);
        }
        #TODO: consumers probably want more control over the response
        return $response;
    }
}
