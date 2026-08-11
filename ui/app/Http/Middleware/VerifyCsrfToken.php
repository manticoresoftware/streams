<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     *
     * @var bool
     */
    protected $addHttpCookie = true;

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        '/manager/importRules',
        '/manager/addRule',
        '/manager/getRulesList',
        '/manager/deleteRule',
        '/manager/kafkaResults',
        '/admin/source/delete',
        '/admin/destination/delete',
        '/admin/process/parseSchema',
        '/admin/process/resolveGoals',
        '/admin/process/add',
        '/admin/process/unassignUser',
        '/admin/process/getToAssignUsersList',
        '/admin/process/getToUnassignUsersList',
        '/admin/process/getSuspendList',
        '/admin/process/getResumeList'
    ];
}
