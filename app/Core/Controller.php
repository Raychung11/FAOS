<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $tpl, array $data = [], string $layout = 'app'): never
    {
        Response::html(View::render($tpl, $data, $layout));
    }

    protected function validate(Request $req, array $rules): array
    {
        $v = Validator::make($req->all(), $rules);
        if ($v->fails()) {
            Response::fail('Validation failed', 422, $v->errors());
        }
        return $v->validated();
    }

    /** Scope a query to the current user's company. */
    protected function companyId(): int
    {
        return (int) (Auth::companyId() ?? 0);
    }
}
