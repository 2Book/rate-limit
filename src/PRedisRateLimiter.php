<?php


namespace RateLimit;


use Predis\Client;
use RateLimit\Exception\LimitExceeded;

class PRedisRateLimiter implements RateLimiter, SilentRateLimiter
{

    /**
     * @var Client
     */
    private $client;

    /**
     * @var string
     */
    private $keyPrefix;

    /**
     * PRedisRateLimiter constructor.
     * @param Client $client
     * @param string $keyPrefix
     */
    public function __construct(Client $client, string $keyPrefix = '')
    {
        $this->client = $client;
        $this->keyPrefix = $keyPrefix;
    }

    /**
     * @param string $identifier
     * @param Rate $rate
     */
    public function limit(string $identifier, Rate $rate)
    {
        $key = $this->key($identifier, $rate->getInterval());

        $current = $this->getCurrent($key);

        if ($current >= $rate->getOperations()) {
            throw LimitExceeded::for($identifier, $rate);
        }

        $this->updateCounter($key, $rate->getInterval());
    }

    /**
     * @param string $identifier
     * @param Rate $rate
     * @return Status
     */
    public function limitSilently(string $identifier, Rate $rate): Status
    {
        $key = $this->key($identifier, $rate->getInterval());

        $current = $this->getCurrent($key);

        if ($current <= $rate->getOperations()) {
            $current = $this->updateCounter($key, $rate->getInterval());
        }

        return Status::from(
            $identifier,
            $current,
            $rate->getOperations(),
            time() + $this->ttl($key)
        );
    }

    /**
     * @param string $identifier
     * @param int $interval
     * @return string
     */
    private function key(string $identifier, int $interval): string
    {
        return "{$this->keyPrefix}{$identifier}:$interval";
    }

    /**
     * @param string $key
     * @return int
     */
    private function getCurrent(string $key): int
    {
        return (int) $this->client->get($key);
    }

    /**
     * @param string $key
     * @param int $interval
     * @return int
     */
    private function updateCounter(string $key, int $interval): int
    {
        $current = $this->client->incr($key);

        if ($current === 1) {
            $this->client->expire($key, $interval);
        }

        return $current;
    }

    /**
     * @param string $key
     * @return int
     */
    private function ttl(string $key): int
    {
        return max((int) ceil($this->client->pttl($key) / 1000), 0);
    }
}