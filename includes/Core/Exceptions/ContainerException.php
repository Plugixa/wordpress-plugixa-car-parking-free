<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Exceptions;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when a service exists but cannot be constructed.
 */
final class ContainerException extends RuntimeException implements ContainerExceptionInterface {
}
