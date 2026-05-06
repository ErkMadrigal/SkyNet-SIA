<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
         return file_get_contents(FCPATH . 'index-welcome.html');
    }
}
