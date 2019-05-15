<?php

namespace Drupal\google_calendar_service;

use Google_Client;
use GuzzleHttp\Client;
use Google_Service_Calendar;

/**
 * Class GoogleHttpClientFactory.
 *
 * @package Drupal\google_calendar_service
 */
class GoogleHttpClientFactory {

  /**
   * Return a configured HttpClient object.
   */
  public function get() {
    $client = new Google_Client();
    $secret_uri = \Drupal::config('google_calendar_service.default')
      ->get('secret_file_uri');
    $email = \Drupal::config('google_calendar_service.default')
      ->get('google_user_email');

    if (!empty($secret_uri)) {
      $secret_file = \Drupal::service('file_system')->realpath($secret_uri);
      $client->setAuthConfig($secret_file);
      $client->setScopes([Google_Service_Calendar::CALENDAR]);
      $client->setSubject($email);
    }

    // Config HTTP client and config timeout.
    $client->setHttpClient(new Client([
      'timeout' => 10,
      'connect_timeout' => 10,
      'verify' => FALSE,
    ]));

    return $client;
  }

}
