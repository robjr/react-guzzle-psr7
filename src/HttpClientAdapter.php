<?php

namespace WyriHaximus\React\GuzzlePsr7;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Ring\Future\FutureArray;
use Psr\Http\Message\RequestInterface;
use React\Dns\Resolver\Factory as DnsFactory;
use React\Dns\Resolver\Resolver as DnsResolver;
use React\EventLoop\LoopInterface;
use React\HttpClient\Client as HttpClient;
use React\HttpClient\Factory as HttpClientFactory;
use WyriHaximus\React\Guzzle\HttpClient\RequestFactory;

class HttpClientAdapter
{
    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var DnsResolver
     */
    protected $dnsResolver;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var RequestFactory
     */
    protected $requestFactory;

    /**
     * @param LoopInterface $loop
     * @param HttpClient $httpClient
     * @param DnsResolver $dnsResolver
     * @param RequestFactory $requestFactory
     */
    public function __construct(
        LoopInterface $loop,
        HttpClient $httpClient = null,
        DnsResolver $dnsResolver = null,
        RequestFactory $requestFactory = null
    ) {
        $this->loop = $loop;

        $this->setDnsResolver($dnsResolver);
        $this->setHttpClient($httpClient);
        $this->setRequestFactory($requestFactory);
    }

    /**
     * @param HttpClient $httpClient
     */
    public function setHttpClient(HttpClient $httpClient = null)
    {
        if (!($httpClient instanceof HttpClient)) {
            $this->setDnsResolver($this->dnsResolver);

            $factory = new HttpClientFactory();
            $httpClient = $factory->create($this->loop, $this->dnsResolver);
        }

        $this->httpClient = $httpClient;
    }

    /**
     * @return HttpClient
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }

    /**
     * @param DnsResolver $dnsResolver
     */
    public function setDnsResolver(DnsResolver $dnsResolver = null)
    {
        if (!($dnsResolver instanceof DnsResolver)) {
            $dnsResolverFactory = new DnsFactory();
            $dnsResolver = $dnsResolverFactory->createCached('8.8.8.8', $this->loop);
        }

        $this->dnsResolver = $dnsResolver;
    }

    /**
     * @return DnsResolver
     */
    public function getDnsResolver()
    {
        return $this->dnsResolver;
    }

    /**
     * @param RequestFactory $requestFactory
     */
    public function setRequestFactory(RequestFactory $requestFactory = null)
    {
        if (!($requestFactory instanceof RequestFactory)) {
            $requestFactory = new RequestFactory();
        }

        $this->requestFactory = $requestFactory;
    }

    /**
     * @return RequestFactory
     */
    public function getRequestFactory()
    {
        return $this->requestFactory;
    }

    /**
     * @param Request $request
     * @return static
     */
    public function __invoke(Request $request)
    {
        $ready = false;
        $promise = new Promise(function () use (&$ready) {
            do {
                $this->loop->tick();
            } while (!$ready);
        });

        $request = static::transformRequest($request);

        $this->requestFactory->create($request, $this->httpClient, $this->loop)->
            then(
                function (array $response) use (&$ready, $promise) {
                    $ready = true;
                    $promise->resolve(static::transformResponse($response));
                }
            )
        ;

        return $promise;
    }

    protected static function transformRequest(RequestInterface $request)
    {
        return [
            'http_method' => $request->getMethod(),
            'url' => (string)$request->getUri(),
            'headers' => $request->getHeaders(),
            'body' => (string)$request->getBody(),
            'client' => [
                'stream' => false,
            ],
        ];
    }

    protected static function transformResponse(array $response)
    {
        return new Response($response['status'], $response['headers'], $response['body']);
    }
}
