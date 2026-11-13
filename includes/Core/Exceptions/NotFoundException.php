<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Exceptions;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when a service id is not registered and is not an existing class.
 */
final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface {
}
