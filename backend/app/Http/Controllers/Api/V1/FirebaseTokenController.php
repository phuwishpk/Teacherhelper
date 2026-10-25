<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Firestore\FirebaseCustomTokens;
use App\Domain\Firestore\FirestoreErrors;
use App\Domain\Notifications\Fcm\FirebaseCredentialsInvalid;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/auth/firebase-token -> {data: {token, uid, expires_at}}
 * (DESIGN §38.4): the custom token the app signs in to Firebase with. An
 * admin working as a user (§29.14) holds that user's Sanctum token, so the
 * uid is the user's: the live screens are theirs.
 */
class FirebaseTokenController extends Controller
{
    public function __invoke(Request $request, FirebaseCustomTokens $tokens): JsonResponse
    {
        return response()->json(['data' => $tokens->issue($request->user())]);
    }

    /** Container factory: a broken key file is a 503, logged without its contents. */
    public static function tokens(): FirebaseCustomTokens
    {
        try {
            return FirebaseCustomTokens::fromConfig();
        } catch (FirebaseCredentialsInvalid $e) {
            Log::error('firestore.credentials_invalid', ['message' => $e->getMessage()]);

            throw FirestoreErrors::unavailable($e->getMessage());
        }
    }
}
