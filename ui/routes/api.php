<?php

use App\Http\Controllers\GraphsController;
use App\Http\Controllers\ManagerController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\VariablesController;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->group(function () {


    Route::match(['get', 'post'], '/admin/users/get', 'AdminController@getUsersList');

    Route::match(['get', 'post'], '/admin/users/add', 'AdminController@addUser');
    Route::match(['get', 'post'], '/admin/users/edit', 'AdminController@editUser');
    Route::match(['get', 'post'], '/admin/users/ban', 'AdminController@banUser');
    Route::match(['get', 'post'], '/admin/users/delete', 'AdminController@deleteUser');
    Route::match(['get', 'post'], '/admin/token/reissue', 'AdminController@reissueToken');


    Route::match(['get', 'post'], '/admin/process/getHostsList', [ProcessController::class,'getGoals']);
    Route::match(['get', 'post'], '/admin/process/resolveGoals', [ProcessController::class,'resolveGoals']);
    Route::match(['get', 'post'], '/admin/process/parseSchema', [ProcessController::class,'parseSchema']);
    Route::match(['get', 'post'], '/admin/process/getSchema', [ProcessController::class,'getSchema']);
    Route::match(['get', 'post'], '/admin/process/extendedInfo/{id}', [ProcessController::class,'getExtendedProcessInfo']);


    Route::match(['get', 'post'], '/admin/process/getSuspendedList', [ProcessController::class,'getSuspendableList']);
    Route::match(['get', 'post'], '/admin/process/suspend', [ProcessController::class, 'suspend']);
    Route::match(['get', 'post'], '/admin/process/getResumedList', [ProcessController::class,'getResumableList']);
    Route::match(['get', 'post'], '/admin/process/resume', [ProcessController::class,'resume']);
    Route::match(['get', 'post'], '/admin/process/streams/get', [ProcessController::class,'getUserStreams']);


    Route::match(['get', 'post'], '/admin/source/get', 'SourceController@getList');
    Route::match(['get', 'post'], '/admin/source/add', 'SourceController@add');
    Route::match(['get', 'post'], '/admin/source/delete', 'SourceController@delete');


    Route::match(['get', 'post'], '/admin/destination/get', 'DestinationController@getList');
    Route::match(['get', 'post'], '/admin/destination/add', 'DestinationController@add');
    Route::match(['get', 'post'], '/admin/destination/delete', 'DestinationController@delete');

    Route::match(['get', 'post'], '/manager/process/get', [ManagerController::class,
        'getProcessingInfo'
    ]);

    Route::match(['get', 'post'], '/admin/process/get', [ProcessController::class,'getList']);
    Route::match(['get', 'post'], '/admin/process/add', [ProcessController::class, 'add']);
    Route::match(['get', 'post'], '/admin/process/assign', [ProcessController::class,'assignUser']);
    Route::match(['get', 'post'], '/admin/process/unassign', [ProcessController::class,'unassignUser']);
    Route::match(['get', 'post'], '/admin/process/remove/{id}', [ProcessController::class,'remove']);

    Route::match(['get', 'post'], '/manager/streams/get', 'ManagerController@getStreams');
    Route::match(['get', 'post'], '/manager/setStream/{id}', 'ManagerController@setStream');

    Route::match(['get', 'post'], '/admin/getGraph/', 'GraphsController@getGraph');
    Route::match(['get', 'post'], '/admin/getRuleStatData/{id}', [GraphsController::class, 'getRuleStatData']);

    Route::group(['middleware' => 'manticore.connection'], function () {
        Route::match(['get', 'post'], '/manager/rules/add', [ManagerController::class, 'addRule']);
        Route::match(['get', 'post'], '/manager/rules/delete/{id}', [ManagerController::class, 'deleteRule']);
        Route::match(['get', 'post'], '/manager/rules/deleteList', [ManagerController::class, 'deleteRulesList']);
        Route::match(['get', 'post'], '/manager/rules/get', [ManagerController::class, 'getRulesList']);
        Route::match(['get', 'post'], '/manager/rules/import', [ManagerController::class, 'importJson']);
        Route::match(['get', 'post'], '/manager/rules/replace', [ManagerController::class, 'replaceRules']);
        Route::match(['get', 'post'], '/manager/rules/searchExtended', [ManagerController::class, 'searchRuleExtended']);

        Route::get('/manager/variables/getList', [VariablesController::class, 'getList']);
        Route::post('/manager/variables/{variable}', [VariablesController::class, 'edit']);
        Route::delete('/manager/variables/{variable}', [VariablesController::class, 'delete']);
    });

    Route::put('/manager/variables/', [VariablesController::class, 'add']);
    Route::get('/manager/variables/{variable}', [VariablesController::class, 'get']);

});



