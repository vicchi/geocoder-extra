<?php

/**
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider;

use Geocoder\Exception\InvalidCredentials;
use Geocoder\Exception\NoResult;
use Geocoder\Exception\UnsupportedOperation;
use Ivory\HttpAdapter\HttpAdapterInterface;

/**
 * @author Josh Moody <jgmoody@gmail.com>
 */
class GeocodioProvider extends AbstractHttpProvider implements Provider
{
    /**
     * @var string
     */
    const GEOCODE_ENDPOINT_URL = 'http://api.geocod.io/v1/geocode?q=%s&api_key=%s';

    /**
     * @var string
     */
    const REVERSE_ENDPOINT_URL = 'http://api.geocod.io/v1/reverse?q=%F,%F&api_key=%s';

    /**
     * @var string
     */
    private $apiKey = null;

    /**
     * @param HttpAdapterInterface $adapter An HTTP adapter.
     * @param string               $apiKey  An API key.
     * @param string               $locale  A locale (optional).
     */
    public function __construct(HttpAdapterInterface $adapter, $apiKey, $locale = null)
    {
        parent::__construct($adapter, $locale);

        $this->apiKey = $apiKey;
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'geocodio';
    }

    /**
     * {@inheritDoc}
     */
    public function geocode($address)
    {
        // This API doesn't handle IPs
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The GeocodioProvider does not support IP addresses.');
        }

        if (null === $this->apiKey) {
            throw new InvalidCredentials('No API Key provided.');
        }

        $query = sprintf(self::GEOCODE_ENDPOINT_URL, urlencode($address), $this->apiKey);

        return $this->executeQuery($query);
    }

    /**
     * {@inheritDoc}
     */
    public function reverse($latitude, $longitude)
    {
        if (null === $this->apiKey) {
            throw new InvalidCredentials('No API Key provided.');
        }

        $query = sprintf(self::REVERSE_ENDPOINT_URL, $latitude, $longitude, $this->apiKey);

        return $this->executeQuery($query);
    }

    /**
     * @param string $query
     *
     * @return array
     */
    protected function executeQuery($query)
    {
        $content = $this->getAdapter()->get($query)->getBody();

        if (null === $content) {
            throw new NoResult(sprintf('Could not execute query: %s', $query));
        }

        $json = json_decode($content, true);

        if (!empty($json['error']) && strtolower($json['error']) == 'invalid api key') {
            throw new InvalidCredentials('Invalid API Key');
        } elseif (!empty($json['error'])) {
            throw new NoResult(sprintf('Error returned from api: %s', $json['error']));
        }

        if (empty($json['results'])) {
            throw new NoResult(sprintf('Could not find results for given query: %s', $query));
        }

        $locations = $json['results'];

        $results = array();

        $ctr = 0;

        foreach ($locations as $location) {
            $ctr++;

            if ($ctr <= $this->getLimit()) {

                $coordinates = $location['location'];
                $address = $location['address_components'];

                //Geocodio does not always return a street, number, or suffix
                if (!isset($address['street']) && isset($json['input']['address_components']['street'])) {
                    //Sometimes Geocodio returns parsed information in the input
                    $addressInput = $json['input']['address_components'];
                    $address['street'] = $addressInput['street'];
                    $address['number'] = $addressInput['number'];
                    $address['suffix'] = $addressInput['suffix'];
                } elseif (!isset($address['street'])) {
                    $address['street'] = '';
                    $address['number'] = ''; // No Street = No Number
                    $address['suffix'] = '';
                }

                if (!empty($address['suffix'])) {
                    $address['street'] .= ' ' . $address['suffix'];
                }

                $results[] = array_merge($this->getDefaults(), array(

                        'latitude'      => $coordinates['lat'] ?: null,
                        'longitude'     => $coordinates['lng'] ?: null,
                        'streetNumber'  => $address['number'] ?: null,
                        'streetName'    => $address['street'] ?: null,
                        'city'          => $address['city'] ?: null,
                        'zipcode'       => $address['zip'] ?: null,
                        'county'        => $address['county'] ?: null,
                        'region'        => $address['state'] ?: null,
                        'country'       => 'US'
                    ));
            }
        }

        return $results;
    }
}
