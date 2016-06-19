<?php

//Base class for all plugins to extend. Contains required methods declared as abstract
abstract class PluginBase {
  //This method is executed when plugin loads. Here you can set hooks to the places you want plugin to actually startup
  public abstract function setStartupHooks();
  //Here you can set hooks to the classes and methods you want to listen events for.
  public abstract function setEventHooks();
}
