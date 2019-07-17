<?php

namespace EdwinFadilah\Passport;

use Carbon\Carbon;
use EdwinFadilah\Passport\Contracts\TokenModelInterface;

class TokenRepository
{
    /**
     * Creates a new Access Token.
     *
     * @param  array  $attributes
     * @return \EdwinFadilah\Passport\Contracts\TokenModelInterface
     */
    public function create($attributes)
    {
        return Passport::token()->create($attributes);
    }

    /**
     * Get a token by the given ID.
     *
     * @param  string  $id
     * @return \EdwinFadilah\Passport\Contracts\TokenModelInterface
     */
    public function find($id)
    {
        return Passport::token()->where('id', $id)->first();
    }

    /**
     * Get a token by the given user ID and token ID.
     *
     * @param  string  $id
     * @param  int  $userId
     * @return \EdwinFadilah\Passport\Contracts\TokenModelInterface|null
     */
    public function findForUser($id, $userId)
    {
        return Passport::token()->where('id', $id)->where('user_id', $userId)->first();
    }

    /**
     * Get the token instances for the given user ID.
     *
     * @param  mixed  $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function forUser($userId)
    {
        return Passport::token()->where('user_id', $userId)->get();
    }

    /**
     * Get a valid token instance for the given user and client.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  \EdwinFadilah\Passport\Contracts\ClientModelInterface  $client
     * @return \EdwinFadilah\Passport\Contracts\TokenModelInterface|null
     */
    public function getValidToken($user, $client)
    {
        return $client->tokens()
                    ->whereUserId($user->getKey())
                    ->where('revoked', 0)
                    ->where('expires_at', '>', Carbon::now())
                    ->first();
    }

    /**
     * Store the given token instance.
     *
     * @param TokenModelInterface $token
     * @return void
     */
    public function save(TokenModelInterface $token)
    {
        $token->save();
    }

    /**
     * Revoke an access token.
     *
     * @param  string  $id
     * @return mixed
     */
    public function revokeAccessToken($id)
    {
        return Passport::token()->where('id', $id)->update(['revoked' => true]);
    }

    /**
     * Check if the access token has been revoked.
     *
     * @param  string  $id
     *
     * @return bool Return true if this token has been revoked
     */
    public function isAccessTokenRevoked($id)
    {
        if ($token = $this->find($id)) {
            return $token->revoked;
        }

        return true;
    }

    /**
     * Find a valid token for the given user and client.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  \EdwinFadilah\Passport\Contracts\ClientModelInterface  $client
     * @return \EdwinFadilah\Passport\Contracts\TokenModelInterface|null
     */
    public function findValidToken($user, $client)
    {
        return $client->tokens()
                      ->whereUserId($user->getKey())
                      ->where('revoked', 0)
                      ->where('expires_at', '>', Carbon::now())
                      ->latest('expires_at')
                      ->first();
    }
}
