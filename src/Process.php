<?php

namespace BomberNet\Process;

use SyncEvent;
use SyncSharedMemory;

abstract class Process
	{
		protected bool $fork=true;
		private SyncEvent $stopEvent;
		private SyncEvent $globalStopEvent;
		
		public function __construct (private readonly string $name='')
			{
				$this->stopEvent=$this->stopEvent ();
				$this->globalStopEvent=self::globalStopEvent ();
			}
		
		public static function globalStop ():void
			{
				static::globalStopEvent ()->fire ();
			}
		
		public function start ():void
			{
				if (!$this->fork) $this->startProcess ();
				$init=$this->successInitEvent ();
				$error=$this->errorStartEvent ();
				if (!pcntl_fork ())
					{
						try
							{
								$this->startProcess ();
							} finally
							{
								$this->errorStartEvent ()->fire ();
							}
						die;
					}
				while (true)
					{
						if ($init->wait (0) || $error->wait (0)) return;
						usleep (10);
					}
			}
		
		public function stop ():void
			{
				$this->stopEvent ()->fire ();
			}
		
		public function isRunning ():bool
			{
				$running=$this->runningStatus ();
				return (bool)unpack ('c',$running->read ())[1];
			}
		
		protected static function globalSyncName (string $suffix=''):string
			{
				return static::class.($suffix?":$suffix":'');
			}
		
		protected function continue ():bool
			{
				return !($this->stopEvent->wait (0) || $this->globalStopEvent->wait (0));
			}
		
		protected function syncName (string $suffix=''):string
			{
				return self::globalSyncName ($this->name.($suffix?":$suffix":''));
			}
		
		private static function globalStopEvent ():SyncEvent
			{
				return new SyncEvent (self::globalSyncName (__METHOD__),true);
			}
		
		private function startProcess ():void
			{
				$stop=function ():false
					{
						$this->stopEvent ()->fire ();
						return false;
					};
				pcntl_signal (SIGINT,$stop);
				pcntl_signal (SIGTERM,$stop);
				$this->init ();
				$this->successInitEvent ()->fire ();
				$running=$this->runningStatus ();
				try
					{
						$running->write (pack ('c',1));
						if ($this->continue ()) $this->main ();
					} finally
					{
						$running->write (pack ('c',0));
						$this->finally ();
					}
			}
		
		private function stopEvent ():SyncEvent
			{
				return new SyncEvent ($this->syncName (__FUNCTION__),true);
			}
		
		private function runningStatus ():SyncSharedMemory
			{
				return new SyncSharedMemory ($this->syncName (__FUNCTION__),1);
			}
		
		private function successInitEvent ():SyncEvent
			{
				return new SyncEvent ($this->syncName (__FUNCTION__.spl_object_id ($this)),true);
			}
		
		private function errorStartEvent ():SyncEvent
			{
				return new SyncEvent ($this->syncName (__FUNCTION__.spl_object_id ($this)),true);
			}
		
		abstract protected function init ():void;
		
		abstract protected function main ():void;
		
		abstract protected function finally ():void;
	}
