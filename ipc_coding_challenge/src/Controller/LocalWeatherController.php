<?php

namespace Drupal\ipc_coding_challenge\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class LocalWeatherController.
 *
 * @package Drupal\ipc_coding_challenge\Controller
 */
class LocalWeatherController extends ControllerBase {

  /**
   * The HTTP client to make external API request.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a LocalWeatherController object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(ClientInterface $http_client) {
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client')
    );
  }

  /**
   * Display local weather.
   *
   * @return array
   *   Markup.
   */
  public function displayLocalWeather() {
    // Rapid API credentials.
    $rapidapi_host = 'community-open-weather-map.p.rapidapi.com';
    $rapidapi_key = '9ff9dfcbcfmsh30af51ba62f5b06p155ae2jsn7f1f7f8f8233';

    // Build URL for Open Weather Map API request.
    $target_city = 'Chicago';
    $request_url = 'https://community-open-weather-map.p.rapidapi.com/weather?q=';
    $request_url .= $target_city;

    $request = $this->httpClient->get($request_url, [
      'headers' => [
        'x-rapidapi-host' => $rapidapi_host,
        'x-rapidapi-key' => $rapidapi_key
      ]
    ]);
    $response = $request->getBody();
    $status_code = $request->getStatusCode();

    $weather_data = json_decode($response);
    $headline = $weather_data->weather[0]->main;
    $description = $weather_data->weather[0]->description;
    $temp = $this->convertKelvinToFahrenheit($weather_data->main->temp);
    $feels_like = $this->convertKelvinToFahrenheit($weather_data->main->feels_like);
    $humidity = $weather_data->main->humidity;

    if ($status_code != 200) {
      return ['#markup' => 'There was an error retrieving data from the Open Weather Map API Server'];
    }
    else {
      return [
        '#theme' => 'local_weather',
        '#target_city' => $target_city,
        '#headline' => $headline,
        '#description' => $description,
        '#temp' => $temp,
        '#feels_like' => $feels_like,
        '#humidity' => $humidity,
      ];
    }

  }

  /**
   * Convert temperature in Kelvin to Fahrenheit.
   *
   * Due to a bug in the Open Weather API, it seems to always return the
   * temperature in Kelvin.
   * If the API were working as documented, it would be enough to pass a 'units'
   * parameter with the request string to retrieve temperature in Fahrenheit.
   *
   * @param float $temp
   *   The temperature in Kelvin.
   *
   * @return float
   *   The temperature in Fahrenheit.
   */
  protected function convertKelvinToFahrenheit($temp) {
    return (($temp - 273.15) * (9 / 5)) + 32;
  }

}
