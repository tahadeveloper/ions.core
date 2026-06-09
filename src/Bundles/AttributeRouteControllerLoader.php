<?php

namespace Ions\Bundles;

use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Loader\AttributeClassLoader;
use Symfony\Component\Routing\Route;

class AttributeRouteControllerLoader extends AttributeClassLoader
{
    /**
     * @param RouteAttribute $annot
     */
    protected function configureRoute(Route $route, \ReflectionClass $class, \ReflectionMethod $method, object $annot)
    {
        // Configure the route based on the attributes
        $route->setPath($annot->getPath() ?? '');
        $route->setDefaults($annot->getDefaults());
        $route->setRequirements($annot->getRequirements());
        $route->setOptions($annot->getOptions());
        $route->setHost($annot->getHost() ?? '');
        $route->setSchemes($annot->getSchemes());
        $route->setMethods($annot->getMethods());
        $route->setCondition($annot->getCondition() ?? '');
    }

    protected function getDefaultRouteName(\ReflectionClass $class, \ReflectionMethod $method): string
    {
        return strtolower($class->getShortName() . '_' . $method->getName());
    }
}
