<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use App\Http\Controllers\GraphsController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\ManagerController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\VariablesController;

Auth::routes(['register' => false]);
Route::get('logout', '\App\Http\Controllers\Auth\LoginController@logout');

Route::get('/', 'HomeController@index')->name('home');
Route::get('/home', 'HomeController@index')->name('home');
Route::get('/admin/home', 'AdminController@home');
Route::get('/admin/getUsersList', 'AdminController@getUsersList');

Route::post('/admin/addUser', 'AdminController@addUser');
Route::post('/admin/editUser', 'AdminController@editUser');
Route::post('/admin/banUser', 'AdminController@banUser');
Route::post('/admin/unbanUser', 'AdminController@unbanUser');

Route::get('/manager/setStream/{id}', 'ManagerController@setStream');


Route::get('/manager/graphs', 'GraphsController@home');
Route::get('/manager/graphs/rule/{id}', 'GraphsController@getRuleStat');
Route::post('/manager/getGraph/', 'GraphsController@getGraph');
Route::post('/manager/getRuleStatData/{id}', [GraphsController::class, 'getRuleStatData']);
Route::get('/manager/emptyAssigns', 'ManagerController@emptyAssigns')->name('emptyAssigns');
Route::get('/manager/results', 'ManagerController@results');
Route::post('/manager/kafkaResults', 'ManagerController@kafkaResults');

Route::get('/admin/source', 'SourceController@index');
Route::get('/admin/source/getList', 'SourceController@getList');
Route::post('/admin/source/add', 'SourceController@add');
Route::post('/admin/source/delete', 'SourceController@delete');

Route::get('/admin/destination', 'DestinationController@index');
Route::get('/admin/destination/getList', 'DestinationController@getList');
Route::post('/admin/destination/add', 'DestinationController@add');
Route::post('/admin/destination/delete', 'DestinationController@delete');


Route::get('/admin/process', 'ProcessController@index');

Route::get('/admin/process/getList', 'ProcessController@getList');
Route::get('/admin/process/new', [ProcessController::class, 'newProcess']);
Route::get('/admin/process/goals', [ProcessController::class, 'getGoals']);
Route::post('/admin/process/resolveGoals', 'ProcessController@resolveHosts');
Route::get('/admin/process/progress', 'ProcessController@getProgress');
Route::post('/admin/process/parseSchema', [ProcessController::class, 'parseSchema']);
Route::get('/admin/process/getSchema', 'ProcessController@getSchema');
Route::get('/admin/process/getAdditive', 'ProcessController@getAdditive');
Route::post('/admin/process/getSuspendList', 'ProcessController@getSuspendList');
Route::post('/admin/process/suspend', 'ProcessController@suspend');
Route::post('/admin/process/getResumeList', 'ProcessController@getResumeList');
Route::post('/admin/process/resume', 'ProcessController@resume');


Route::post('/admin/process/getToAssignUsersList', 'ProcessController@getToAssignUsersList');
Route::post('/admin/process/getToUnassignUsersList', 'ProcessController@getToUnassignUsersList');
Route::post('/admin/process/assignUser', [ProcessController::class, 'assignUser']);
Route::post('/admin/process/unassignUser', 'ProcessController@unassignUser');


Route::post('/admin/process/add', 'ProcessController@add');
Route::get('/admin/process/remove/{id}', 'ProcessController@remove');

Route::get('/admin/logs', [LogsController::class, 'index']);
Route::get('/admin/logs/view', [LogsController::class, 'view']);

Route::get('/tokens', 'ApiTokenController@get');
Route::get('/tokens/update', 'ApiTokenController@update');
Route::get('/tokens/remove', 'ApiTokenController@remove');

Route::group(['middleware' => 'manticore.connection'], function () {
    Route::any('/manager/addRule', [ManagerController::class, 'addRule']);
    Route::any('/manager/deleteRule/{id}', [ManagerController::class, 'deleteRule']);
    Route::any('/manager/getRulesList', [ManagerController::class, 'getRulesList']);
});


Route::get('/manager/home', [ManagerController::class, 'home']);
Route::post('/manager/importRules', [ManagerController::class, 'importRules']);


Route::get('/manager/variables/getList', [VariablesController::class, 'getList']);
Route::post('/manager/variables/{variable}', [VariablesController::class, 'edit']);
Route::delete('/manager/variables/{variable}', [VariablesController::class, 'delete']);


Route::get('/manager/variables', [VariablesController::class, 'index']);
Route::put('/manager/variables/', [VariablesController::class, 'add']);
Route::get('/manager/variables/{variable}', [VariablesController::class, 'get']);




