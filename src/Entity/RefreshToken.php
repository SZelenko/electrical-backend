<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

/**
 * `username` holds the user's UUID (User::getUserIdentifier()). There is no FK: revoke tokens
 * by username explicitly when a user is deleted or changes the password.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_token')]
#[ORM\Index(name: 'idx_refresh_token__username', fields: ['username'])]
class RefreshToken extends BaseRefreshToken {}
