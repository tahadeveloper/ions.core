<?php

namespace Ions\Support;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Route extends \Symfony\Component\Routing\Annotation\Route
{

}