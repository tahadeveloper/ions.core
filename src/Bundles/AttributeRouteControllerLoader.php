<?php

namespace Ions\Bundles;

use Symfony\Component\Routing\Loader\AnnotationClassLoader;
use Symfony\Component\Routing\Route;

class AttributeRouteControllerLoader extends AnnotationClassLoader
{
    protected function configureRoute(Route $route, \ReflectionClass $class, \ReflectionMethod $method, object $annot)
    {
        // Configure the route based on the attributes
        $route->setPath($annot->path);
        $route->setDefaults($annot->defaults ?? []);
        $route->setRequirements($annot->requirements ?? []);
        $route->setOptions($annot->options ?? []);
        $route->setHost($annot->host ?? '');
        $route->setSchemes($annot->schemes ?? []);
        $route->setMethods($annot->methods ?? []);
        $route->setCondition($annot->condition ?? '');
    }

    protected function getDefaultRouteName(\ReflectionClass $class, \ReflectionMethod $method): string
    {
        return strtolower($class->getShortName() . '_' . $method->getName());
    }
}