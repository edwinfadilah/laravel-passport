<?php
/**
 * Created by PhpStorm.
 * User: bahasolaptop2
 * Date: 12/09/19
 * Time: 10:09
 */

namespace EdwinFadilah\Passport\ResponseTypes;


use League\OAuth2\Server\ResponseTypes\AbstractResponseType;
use Psr\Http\Message\ResponseInterface;

class CodeResponse extends AbstractResponseType
{
    protected $code;
    protected $expire_date_time;
    protected $state;

    /**
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function generateHttpResponse(ResponseInterface $response)
    {
        /** @var \DateTime $expire_date_time */
        $expire_date_time = $this->expire_date_time;
        $responseParams = [
            'code'   => $this->code,
            'expires_in'   => $expire_date_time->getTimestamp()
        ];

        $response = $response
            ->withStatus(200)
            ->withHeader('pragma', 'no-cache')
            ->withHeader('cache-control', 'no-store')
            ->withHeader('content-type', 'application/json; charset=UTF-8');

        $response->getBody()->write(json_encode($responseParams));

        return $response;
    }

    public function setCode($code)
    {
        $this->code = $code;
    }

    public function setExpireDateTime($expire_date_time)
    {
        $this->expire_date_time = $expire_date_time;
    }

    public function setState($state)
    {
        $this->state = $state;
    }
}