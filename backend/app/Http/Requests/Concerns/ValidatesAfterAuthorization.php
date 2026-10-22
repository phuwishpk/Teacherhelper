<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\ValidatedInput;

/**
 * Defers a FormRequest's validation from container resolution to the first
 * validated() / safe() call, so the controller can look the row up and run
 * its policy first. A request for someone else's row then answers 403/404
 * whatever the body says, instead of a 422 that would reveal whether the id
 * exists and what the body should look like (DESIGN §16.1 (a),
 * tests/Feature/Security/AuthorizationMatrixTest).
 *
 * A controller that takes such a request must read the body only through
 * validated() or safe(): input() would bypass validation.
 */
trait ValidatesAfterAuthorization
{
    private bool $deferredValidationDone = false;

    /** Called by the container on resolution: intentionally does nothing. */
    public function validateResolved(): void
    {
        // See validated() / safe().
    }

    /**
     * @param  array|int|string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $this->runDeferredValidation();

        return parent::validated($key, $default);
    }

    /**
     * @return ValidatedInput|array
     */
    public function safe(?array $keys = null)
    {
        $this->runDeferredValidation();

        return parent::safe($keys);
    }

    private function runDeferredValidation(): void
    {
        if (! $this->deferredValidationDone) {
            $this->deferredValidationDone = true;
            parent::validateResolved();
        }
    }
}
