<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2024 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace Glpi\Controller;

use Glpi\Api\APIRest;
use Glpi\Application\ErrorHandler;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ApiRestController extends AbstractController
{
    #[Route(
        "/apirest.php{request_parameters}",
        name: "glpi_api_rest",
        requirements: [
            'request_parameters' => '.*',
        ]
    )]
    #[SecurityStrategy('no_check')]
    public function __invoke(Request $request): Response
    {
        $_SERVER['PATH_INFO'] = $request->get('request_parameters');

        return new StreamedResponse(function () {
            // Ensure errors will not break API output.
            ErrorHandler::getInstance()->disableOutput();

            $api = new APIRest();
            $api->call();
        });
    }
}
