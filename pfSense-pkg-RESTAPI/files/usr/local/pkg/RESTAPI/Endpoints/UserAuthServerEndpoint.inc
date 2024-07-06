<?php

namespace RESTAPI\Endpoints;

require_once 'RESTAPI/autoloader.inc';

use RESTAPI\Core\Endpoint;

/**
 * Defines an Endpoint for interacting with a single AuthServer Model object at
 * /api/v2/user/auth_server.
 */
class UserAuthServerEndpoint extends Endpoint {
    public function __construct() {
        # Set Endpoint attributes
        $this->url = '/api/v2/user/auth_server';
        $this->model_name = 'AuthServer';
        $this->request_method_options = ['GET', 'POST', 'PATCH', 'DELETE'];

        # Construct the parent Endpoint object
        parent::__construct();
    }
}