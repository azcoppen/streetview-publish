<?php

/**
* Upload Controller
*
* Uploads a single 360 photo to Street View API
*
* LICENSE: Commercial
*
* @category   Street View
* @package    Healthtrust
* @subpackage Controllers
* @author     Cstraight Media (cstr8@cstraight.com)
* @copyright  Copyright (c) 2018 CStraight Media
* @license    http://framework.zend.com/license   BSD License
* @version    $Id:$
* @since      File available since Release 1.0
*
* Usage:
* $streetview = new Streetview\Upload ([
*    'api_key' => '123456',
*    'access_token' => 'abcdefg'
* ]);
*
* $google_id = $streetview->
*       photo('./360_panorama.jpg')
*       ->metadata ([
*          'latitude'  => 21.2,
*          'longitude' => -73.4,
*          'created'   => 039383757
*        ])
*       ->upload();
*/

namespace Streetview;

use \GuzzleHttp\Client;
use \Carbon\Carbon;
use \Exception;

use Exceptions\BadConfigException;
use Exceptions\BadResponseException;
use Exceptions\InvalidPhotoException;
use Exceptions\BadMetaDataException;

/**
 * Handles the upload of a file to the API
 */
class Upload {

  /**
  * Google API URL
  * @var string
  */
  protected $api_base_url = "https://streetviewpublish.googleapis.com/v1/";

  /**
  * Allowable file types for upload
  * @var array
  */
  protected $mimes = ['image/png', 'image/jpg', 'image/jpeg'];

  /**
  * Min width of image file, needs to be 7.5MP / 2:1 ratio
  * @var int
  */
  protected $min_width = 4096;

  /**
  * Min height of image file, needs to be 7.5MP / 2:1 ratio
  * @var int
  */
  protected $min_height = 2048;

  /**
  * Configuration package - api key, token
  * @var array
  */
  private $config = [];

  /**
  * Instance of Guzzle HTTP client
  * @var Client
  */
  private $http_client;

  /**
  * Holds the new upload URL supplied by Google
  * @var string
  */
  private $upload_url;

  /**
  * Location on disk of the file to upload
  * @var string
  */
  private $file_to_upload;

  /**
  * XMP information to include - lat/lng, heading, time etc
  * @var array
  */
  private $metadata = [];

  /**
  * Holds the reference to the newly created photo
  * @var string
  */
  public $google_photo_id;

  /**
   * Constructor: set the API access configuration
   *
   * @param array $ config A simple array with api key, token etc
   * @return string New upload URL
   * @throws BadConfigException
   */
  public function __construct ( array $config ) : string {
    if ( !count($config) ) {
      throw new BadConfigException ("Your configuration cannot be empty.");
    }

    if ( !array_key_exists('api_key', $config) || (array_key_exists('api_key', $config) && empty($config['api_key'])) ) {
      throw new BadConfigException ("You must include an API key.");
    }

    if ( !array_key_exists('access_token', $config) || (array_key_exists('access_token', $config) && empty($config['access_token'])) ) {
      throw new BadConfigException ("You must include an OAuth access token.");
    }

    $this->config = $config;
    $this->http_client = new Client;
    return $this->upload_url ();
  }

  /**
   * Get a new upload URL to send the photo to
   *
   * @throws BadResponseException
   */
  private function upload_url () {
    if ( !$this->upload_url ) {
      $request = $this->http_client->post ( $this->api_base_url . 'photo:startUpload?key=' . $this->config ['api_key'], [
          'headers' => [
              'Authorization'   => 'Bearer ' . $this->config ['access_token'],
              'Content-Length'  => '0',
          ]
      ]);

      $response_json = json_decode ( $request->getBody() );

      if ( !is_object ($response_json) || !isset($response_json->uploadUrl) ) {
        throw new BadResponseException ( "Response JSON error: " json_last_error_msg() );
      }

      $this->upload_url = $response_json->uploadUrl;
    }
    return $this->upload_url;
  }

  /**
   * Set or get the local file to read and upload
   *
   * @param string $path_to_file A file to open (could be URL, but not good if it is)
   * @return object Self - for chaining
   * @throws InvalidPhotoException
   */
  public function photo ( string $path_to_file ) {

    if ( !file_exists($path_to_file) || !is_readable ($path_to_file) || !in_array ( mime_content_type($path_to_file), $this->mimes ) ) {
      throw new InvalidPhotoException ("Photo does not exist or is not readable.");
    } else {

      if ( imagesx ($path_to_file) < $this->min_width || imagesy ($path_to_file) < $this->min_height ) {
        throw new InvalidPhotoException ("Image files must be at least 7.5 megapixels (~5000px wide, or 4K resolution)");
      }

      $this->file_to_upload = $path_to_file;
    }

    return $this;
  }

  /**
   * Set the XMP metadata for the 360 photo
   *
   * @param array $data Heading, latitude, longitude, creation time etc
   * @return object Self - for chaining
   * @throws BadMetaDataException
   */
  public function metadata ( array $data ) {
    if ( !count($data) ) {
      throw new BadMetaDataException ("Your photo metadata cannot be empty.");
    }

    if ( !array_key_exists('latitude', $data) || (array_key_exists('latitude', $data) && empty($data['latitude'])) ) {
      throw new BadMetaDataException ("You must set a latitude.");
    }

    if ( !preg_match('/^(\+|-)?(?:90(?:(?:\.0{1,6})?)|(?:[0-9]|[1-8][0-9])(?:(?:\.[0-9]{1,6})?))$/', $data['latitude']) ) {
      throw new BadMetaDataException ("Invalid latitude.");
    }

    if ( !array_key_exists('longitude', $data) || (array_key_exists('longitude', $data) && empty($data['longitude'])) ) {
      throw new BadMetaDataException ("You must set a longitude.");
    }

    if ( !preg_match('/^(\+|-)?(?:180(?:(?:\.0{1,6})?)|(?:[0-9]|[1-9][0-9]|1[0-7][0-9])(?:(?:\.[0-9]{1,6})?))$/', $data['longitude']) ) {
      throw new BadMetaDataException ("Invalid longitude.");
    }

    if ( !array_key_exists('created', $data) || (array_key_exists('created', $data) && empty($data['created'])) ) {
      throw new BadMetaDataException ("You must set a creation time in seconds.");
    } else {
      if ( !is_numeric ($data['created']) ) {
        $data['created'] = Carbon::parse ( $data['created'] )->timestamp;
      }
    }

    $this->metadata = $data;
    return $this;
  }

  /**
   * Execute the 2-stage upload and get a Google photo ID
   *
   * @return string ID of the photo stored by Google
   * @throws BadConfigException
   * @throws InvalidPhotoException
   * @throws BadMetaDataException
   * @throws BadResponseException
   */
  public function upload () {
    if ( !count($this->config) ) {
      throw new BadConfigException ("Configuration cannot be empty.");
    }

    if ( !$this->file_to_upload) {
      throw new InvalidPhotoException ("Photo cannot be missing.");
    }

    if ( !count($this->metadata) ) {
      throw new BadMetaDataException ("Photo metadata cannot be empty.");
    }

    if ( !$this->upload_url) {
      throw new BadConfigException ("Upload URL reference cannot be empty or broken.");
    }

    $body = fopen ( $this->file_to_upload, 'rb' );
    $upload = $this->http_client->post ( $this->upload_url, [
      'body'    => $body,
      'headers' => [
          'Authorization'   => 'Bearer ' . $this->config ['access_token'],
      ]
    ]);

    $meta = $this->http_client->post ( $this->api_base_url . 'photo', [
      'headers' => [
          'Authorization'   => 'Bearer ' . $this->config ['access_token'],
      ],
      'json'    => [
        'uploadReference' => [
          'uploadUrl' => $this->upload_url
        ],
        'pose' => [
          //'heading' => '',
          'latLngPair' => [
            'latitude'  => $this->metadata['latitude'],
            'longitude' => $this->metadata['longitude'],
          ]
        ],
        'captureTime' => [
          'seconds' => $this->metadata['created'],
        ]
      ]
    ]);

    $response_json = json_decode ( $meta->getBody() );

    if ( !is_object ($response_json) || !isset($response_json->photoId) ) {
      throw new BadResponseException ( "Response JSON error: " json_last_error_msg() );
    }

    $this->google_photo_id = $response_json->photoId->id;

    return $this->google_photo_id;
  }

}
