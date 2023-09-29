<?php
/** MajPostit Task
* Version			: 4.1.0
* Package			: Joomla 4.1
* copyright 		: Copyright (C) 2023 ConseilGouz. All rights reserved.
* license    		: http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
*
*/
namespace ConseilGouz\Plugin\Task\Majpostit\Extension;
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\Registry\Registry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Date\Date;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use ConseilGouz\Component\CGResa\Site\Controller\ResaController;

class Majpostit extends CMSPlugin implements SubscriberInterface
{
		use TaskPluginTrait;


	/**
	 * @var boolean
	 * @since 4.1.0
	 */
	protected $autoloadLanguage = true;
	/**
	 * @var string[]
	 *
	 * @since 4.1.0
	 */
	protected const TASKS_MAP = [
		'majpostit' => [
			'langConstPrefix' => 'PLG_TASK_MAJPOSTIT',
			'form'            => 'majpostit',
			'method'          => 'majpostit',
		],
	];
	protected $myparams;

	/**
	 * @inheritDoc
	 *
	 * @return string[]
	 *
	 * @since 4.1.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onTaskOptionsList'    => 'advertiseRoutines',
			'onExecuteTask'        => 'standardRoutineHandler',
			'onContentPrepareForm' => 'enhanceTaskItemForm',
		];
	}

	protected function majpostit(ExecuteTaskEvent $event): int {
		$app = Factory::getApplication();
		$this->myparams = $event->getArgument('params');
		if ($this->myparams->typecal == 'jevents') {
			return $this->getJEvents(); // JEvents
		} elseif ($this->myparams->typecal == 'dpcalendar') {
			return $this->getDPCalendars(); // DPCalendar
		} else {
			return $this->getCGResa(); // CG Resa
		}
	}
 	// recherche du post-it de la categorie
	// - remplace la zone "editor" par le contenu de l'evenement
	// - mis a jour des dates debut et fin de l'evenement
	//
	function upd_Post_it($st_type,$date_end,$st_desc) {
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$query->select("id, params")
		->from("#__modules")
		->where("module IN ('mod_postit','mod_cg_memo') AND title like CONCAT('post-it ','" . $st_type."','%') AND published > 0;");
		$db->setQuery($query);
		$result = $db->loadAssocList();
		foreach ($result as $res) {
			$st = utf8_encode($st_desc);
			$params =  new Registry($res['params']);
		// remplace le contenu de "editor" par le contenu de l'evenement
			$params->set('editor',$st);
			try {
				$query = $db->getQuery(true);
				$query->update("#__modules")
				->set('params = '.$db->quote($params->toString()).', publish_up = now() - INTERVAL 1 DAY,publish_down = '.$db->quote($date_end))
				->where("id = ".$res['id']);
				$db->setQuery($query);
				$db->execute();
			}
			catch ( Exception $e ) {
			}
		}
		return true;
	}
    // JEvents: recuperation du prochain evenement d'une categorie
	// attention : la categorie de l'evenement doit etre dans le nom du post-it
	//             sous la forme 'post-it <categorie>' ou 'post-it <categorie> phone'
	//
	public function getJEvent($type) {
		$jour = array("Dimanche","Lundi","Mardi","Mercredi","Jeudi","Vendredi","Samedi"); 

		$db = Factory::getDbo();
		$query = $db->getQuery(true);	
		$query->select("vrepet.rp_id as id,vrepet.startrepeat as dtstart, vrepet.endrepeat as dtend,detail.description, detail.summary")
		->from("#__jevents_vevdetail detail ")
		->innerJoin("#__jevents_repetition vrepet ON detail.evdet_id = vrepet.eventdetail_id ")
		->innerJoin("#__jevents_vevent vevent ON vevent.ev_id = vrepet.eventid ")
		->innerJoin("#__categories cat ON vevent.catid = cat.id")
		->where("cat.extension = 'com_jevents' AND cat.alias = '".$type."' AND vevent.state > 0  AND vrepet.startrepeat > now() AND vrepet.endrepeat > now()")
		->order("vrepet.startrepeat ASC")
		->setLimit("1");
		$db->setQuery($query);
		$res = $db->loadAssoc();
        if (!$res || !isset($res['id']) || ($res['id'] == 0)) { // pas d'evenement: on sort
		    return TaskStatus::OK;
        }
		$rac = 'index.php/'.$this->myparams->menupath.'/detailevenement/';
        // $rac .= date('Y/m/d',$res['dtstart']);
		$rac .=	'/'.$res['id']; // '/-/'.$type;	
		if ((strlen($res['description']) == 0) || ($this->myparams->typeaff == "title") ) {
		// pas de description: on en genere une a partir du titre
			$desc = htmlentities($res['summary'],ENT_NOQUOTES,'utf-8').'<br/>le '.$jour[date('w',strtotime($res['dtstart']))].' '.date('d/m',strtotime($res['dtstart']));
			// heure debut = 00:00 => toute la journee
			if (!(date('H\hi',strtotime($res['dtstart'])) == '00h00' )) $desc .= htmlentities(' à partir de '.date('H\hi',strtotime($res['dtstart'])),ENT_NOQUOTES,'utf-8');
		} else {
		// on limite la taille pour le post-it: 1ere paragraphe
			$desc = substr($res['description'],0,strpos($res['description'],'</p>'));
			$desc = self::cleantext($desc);
			$desc = htmlentities($desc,ENT_NOQUOTES,'utf-8');
		}
 		$desc = '<a href="'.$rac.'">'.$desc.'</a>';  
		$this->upd_Post_it($type,date("Y/m/d H:i:s",strtotime($res['dtend'])),$desc);
		return TaskStatus::OK;
	}
	// JEvents: recherche des evenements des categories 
	function getJEvents() {
		$arr = $this->myparams->postits; 
		$result = "";
		foreach ($arr as $key) {
		    $result = $this->getJEvent($key);
		}
		return TaskStatus::OK;
	}
    // DPCalendar: recuperation du prochain evenement d'une categorie
	// attention : la categorie de l'evenement doit etre dans le nom du post-it
	//             sous la forme 'post-it <categorie>'
	//
	public function getDPCalendar($type) {
		$db = Factory::getDbo();
		$query = $db->getQuery(true);	
		$query->select("detail.id,detail.start_date, detail.end_date,detail.title, detail.description, detail.all_day")
		->from("#__dpcalendar_events detail ")
		->innerJoin("#__categories cat ON detail.catid = cat.id")
		->where("cat.extension = 'com_dpcalendar' AND cat.alias = '".$type."' AND detail.start_date > now() AND detail.end_date > now()")
		->order("detail.start_date ASC")
		->setLimit("1");
		$db->setQuery($query);
		$res = $db->loadAssoc();
        if (!$res || !isset($res['id']) || ($res['id'] == 0)) { // pas d'evenement: on sort
		    return TaskStatus::OK;
        }
		$rac = 'index.php/'.$this->myparams->menupathdp.'/'.$res['id'];	
		if ((strlen($res['description']) == 0) || ($this->myparams->typeaff == "title") ) {
		// pas de description: on en genere une a partir du titre
			$desc = htmlentities($res['title'],ENT_NOQUOTES,'utf-8').' le '.date('d/m',strtotime($res['start_date']));
			// 1.0.6 : pas d'heure de debut si allday
			if ($res['all_day'] = '0') $desc .= htmlentities(' à partir de '.date('H\hi',strtotime($res['start_date'])),ENT_NOQUOTES,'utf-8');
		} else {
		// on limite la taille pour le post-it: 1ere paragraphe
			$desc = substr($res['description'],0,strpos($res['description'],'</p>'));
			$desc = self::cleantext($desc);
			$desc = htmlentities($desc,ENT_NOQUOTES,'utf-8');
		}
 		$desc = '<a href="'.$rac.'">'.$desc.'</a>';  
		$this->upd_Post_it($type,$res['end_date'],$desc);
		return TaskStatus::OK;
	}	
	// DPCalendar : recherche des evenements des categories 
	function getDPCalendars() {
		$arr = $this->params->postitsdp; 
		$result = "";
		foreach ($arr as $key) {
		    $result = $this->getDPCalendar($key);
		}
		return TaskStatus::OK;
	}
	// allevents: recuperation du prochain evenement d'un agenda
	// attention : la categorie de l'evenement doit etre dans le nom du post-it
	//             sous la forme 'post-it <categorie>'
	//
	// CG resa: recuperation du prochain evenement 
	//
	public function getCGResaEvent($type) {
		$jour = array("Dimanche","Lundi","Mardi","Mercredi","Jeudi","Vendredi","Samedi"); 
		
		$events_date = array();
		$events_lib = array();
		$date_format="Y-m-d H:i"; // 1.0.24 : add time

		$params = ResaController::getParams();
		$events = $params['events'];
		if (! $events)  return TaskStatus::OK;
		foreach ($events as $adate) {
			if (strtotime($adate['event'].' 23:59:59') < time())  {
				continue;
			}
			$heure = str_replace('h',':',$adate['ouv']);
			array_push($events_date, date($date_format,strtotime($adate['event'].'T'.$heure) )); 
			array_push($events_lib, $adate['event_lib']);
		}
		if (count($events_date) == 0) return TaskStatus::OK;
		aSort($events_date);
		$desc =$events_lib[0];
		$desc = self::cleantext($desc);
		$desc = htmlentities($desc,ENT_NOQUOTES,'utf-8');
		$desc = $jour[date('w',strtotime($events_date[0]))].' '.date('d/m',strtotime($events_date[0])).' : '.$desc;
		if ($this->myparams->cgresamenu != '') {
			$id = $this->myparams->cgresamenu;
			$route = "index.php?Itemid={$id}";
			$desc = '<a href="'.$route.'">'.$desc.'</a>';
		}
		$this->upd_Post_it($type,$events_date[0],$desc);
		$this->upd_Post_it($type.' phone',$events_date[0],$desc);
		return TaskStatus::OK;
		
	}
	// CG Resa: recherche des evenements des agendas
	function getCGResa() {
		// check if CG Resa is installed and enabled
		$db = Factory::getDbo();
		$db->setQuery("SELECT enabled FROM #__extensions WHERE name = 'COM_CGRESA'");
		$is_enabled = $db->loadResult();        
		if ($is_enabled != 1) { return true;} 
		$arr = $this->myparams->postitcg; 
		$result = "";
		foreach ($arr as $key) {
			$this->getCGResaEvent($key);
		}
		return TaskStatus::OK;
	}
	function cleantext($text)
	{
		$text = str_replace('<p>', ' ', $text);
		$text = str_replace('</p>', ' ', $text);
		$text = strip_tags($text, '<br>');
		$text = trim($text);
		return $text;
	}	
}