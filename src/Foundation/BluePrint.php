<?php
namespace Ions\Foundation;

use Ions\Support\Request;

/**
 * Interface blueprint
 */
interface BluePrint
{
    /**
     * init state for controller before any other method
     * used to create instance before call method
     * @param Request $request
     * @return void
     */
    public function _initState(Request $request):void;

    public function _loadInit(Request $request):void;

    public function _loadedState(Request $request):void;

    /**
     * call before closing request
     * @param Request $request
     * @return void
     */
    public function _endState(Request $request):void;
}
