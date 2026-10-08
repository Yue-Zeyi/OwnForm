<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;

class Index extends BaseController
{
    public function index()
    {
        return redirect('/admin/');
    }
}
