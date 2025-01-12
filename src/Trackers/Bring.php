<?php

namespace Sauladam\ShipmentTracker\Trackers;

use Carbon\Carbon;
use Sauladam\ShipmentTracker\DataProviders\Registry;
use Sauladam\ShipmentTracker\Event;
use Sauladam\ShipmentTracker\Track;

class Bring extends AbstractTracker
{

    /**
     * @var string
     */
    //protected $trackingUrl = 'https://tracking.bring.com/api/v2/tracking.json';
    protected $trackingUrl = 'https://api.bring.com/tracking/api/v2/tracking.json';

    /**
     * @var string
     */
    protected $language = 'en';


    /**
     * Build the url to the user friendly tracking site. In most
     * cases this is also the endpoint, but sometimes the tracking
     * data must be retrieved from another endpoint.
     *
     * @param string $trackingNumber
     * @param string|null $language
     * @param array $params
     *
     * @return string
     */
    public function trackingUrl($trackingNumber, $language = null, $params = [])
    {
        $language = $language ?: $this->language;

        $additionalParams = !empty($params) ? $params : $this->trackingUrlParams;

        $qry = http_build_query(array_merge([
            'q' => $trackingNumber,
        ], $additionalParams));

        return $this->trackingUrl . '?' . $qry;
    }

    /**
     * Build the response array.
     *
     * @param string $response
     *
     * @return \Sauladam\ShipmentTracker\Track
     */
    protected function buildResponse($response)
    {
        $contents = json_decode($response, true)['consignmentSet'][0]['packageSet'][0];

        $track = new Track;

        foreach ($contents['eventSet'] as $event) {
            $location = '';
            if(array_key_exists('city', $event)) {
                $location = $event['city'];
            }
            if('DELIVERY_CHANGED' == $event['status']) {
                continue;
            }
            $track->addEvent(Event::fromArray([
                'location'    => $location,
                'description' => $event['description'],
                'date'        => $this->getDate($event['dateIso']),
                'status'      => $status = $this->resolveState($event['status'])
            ]));
        }

        return $track->sortEvents();
    }

    /**
     * Get the contents of the given url.
     *
     * @param string $url
     *
     * @return string
     * @throws \Exception
     */
    protected function fetch($url)
    {
        try {
            return $this->getDataProvider()->client->get($url, $this->buildRequest())
                ->getBody()
                ->getContents();

        } catch (\Exception $e) {
            throw new \Exception("Could not fetch tracking data for [{$this->parcelNumber}].");
        }
    }

    /**
     * @return array
     */
    protected function buildRequest()
    {
        return [
            'headers' => [
                'Accept' => 'application/json',
                'X-Mybring-API-Uid' => 'teh@dkwebshops.dk',
                'X-Mybring-API-Key' => 'e288d2e0-6064-4b01-b36d-d290856a2cee',
                //'X-Bring-Client-URL' => '',
            ],

        ];
    }

    private function getDate($eventTime)
    {
        return Carbon::parse($eventTime);
    }

    private function resolveState($status)
    {
        switch ($status) {
            case 'PRE_NOTIFIED':
            case 'IN_TRANSIT':
            case 'TRANSPORT_TO_RECIPIENT':
            case 'ATTEMPTED_DELIVERY':
                return Track::STATUS_IN_TRANSIT;
            case 'DELIVERED':
                return Track::STATUS_DELIVERED;
            case 'READY_FOR_PICKUP':
                return Track::STATUS_PICKUP;
            default:
                return Track::STATUS_UNKNOWN;
        }
    }
}
