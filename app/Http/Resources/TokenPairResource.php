<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TokenPairResource extends JsonResource
{
    /**
     * @param  array{access_token: string, refresh_token: string, token_type: string, expires_in: int}  $resource
     */
    public function toArray(Request $request): array
    {
        return [
            'accessToken' => $this->resource['access_token'],
            'refreshToken' => $this->resource['refresh_token'],
            'tokenType' => $this->resource['token_type'],
            'expiresIn' => $this->resource['expires_in'],
        ];
    }
}
