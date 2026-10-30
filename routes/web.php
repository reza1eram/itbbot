<?php

use Illuminate\Support\Facades\Route;

// Everything lives in the exam module (app/Modules/AnonymousExam/routes); the root just points to the panel.
Route::redirect('/', '/exam-admin');
