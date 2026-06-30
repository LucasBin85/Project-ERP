<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccountTemplate;
use Illuminate\Http\Request;

class ChartOfAccountTemplateController extends Controller {
    public function index() {
        $templates = ChartOfAccountTemplate::with('children')->whereNull('parent_id')->get();
        return response()->json($templates);
    }
}
