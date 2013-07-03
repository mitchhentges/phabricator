<?php

/**
 * Render a table of Differential revisions.
 */
final class DifferentialRevisionListView extends AphrontView {

  private $revisions;
  private $flags = array();
  private $drafts = array();
  private $handles;
  private $fields;
  private $highlightAge;
  private $header;
  private $noDataString;

  public function setNoDataString($no_data_string) {
    $this->noDataString = $no_data_string;
    return $this;
  }

  public function setHeader($header) {
    $this->header = $header;
    return $this;
  }

  public function setFields(array $fields) {
    assert_instances_of($fields, 'DifferentialFieldSpecification');
    $this->fields = $fields;
    return $this;
  }

  public function setRevisions(array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');
    $this->revisions = $revisions;
    return $this;
  }

  public function setHighlightAge($bool) {
    $this->highlightAge = $bool;
    return $this;
  }

  public function getRequiredHandlePHIDs() {
    $phids = array();
    foreach ($this->fields as $field) {
      foreach ($this->revisions as $revision) {
        $phids[] = $field->getRequiredHandlePHIDsForRevisionList($revision);
      }
    }
    return array_mergev($phids);
  }

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function loadAssets() {
    $user = $this->user;
    if (!$user) {
      throw new Exception("Call setUser() before loadAssets()!");
    }
    if ($this->revisions === null) {
      throw new Exception("Call setRevisions() before loadAssets()!");
    }

    $this->flags = id(new PhabricatorFlagQuery())
      ->setViewer($user)
      ->withOwnerPHIDs(array($user->getPHID()))
      ->withObjectPHIDs(mpull($this->revisions, 'getPHID'))
      ->execute();

    $this->drafts = id(new DifferentialRevisionQuery())
      ->setViewer($user)
      ->withIDs(mpull($this->revisions, 'getID'))
      ->withDraftRepliesByAuthors(array($user->getPHID()))
      ->execute();

    return $this;
  }

  public function render() {

    $user = $this->user;
    if (!$user) {
      throw new Exception("Call setUser() before render()!");
    }

    $fresh = PhabricatorEnv::getEnvConfig('differential.days-fresh');
    if ($fresh) {
      $fresh = PhabricatorCalendarHoliday::getNthBusinessDay(
        time(),
        -$fresh);
    }

    $stale = PhabricatorEnv::getEnvConfig('differential.days-stale');
    if ($stale) {
      $stale = PhabricatorCalendarHoliday::getNthBusinessDay(
        time(),
        -$stale);
    }

    Javelin::initBehavior('phabricator-tooltips', array());
    require_celerity_resource('aphront-tooltip-css');

    $flagged = mpull($this->flags, null, 'getObjectPHID');

    foreach ($this->fields as $field) {
      $field->setHandles($this->handles);
    }

    $list = new PhabricatorObjectItemListView();
    $list->setCards(true);

    foreach ($this->revisions as $revision) {
      $item = new PhabricatorObjectItemView();
      $rev_fields = array();
      $icons = array();

      $phid = $revision->getPHID();
      if (isset($flagged[$phid])) {
        $icons['flag'] = array(
          'icon' => 'flag-'.$flagged[$phid]->getColor(),
        );
      }
      if (array_key_exists($revision->getID(), $this->drafts)) {
        $icons['draft'] = array(
          'icon' => 'file-grey',
        );
      }

      $modified = $revision->getDateModified();

      foreach ($this->fields as $field) {
        if (($fresh || $stale) &&
            $field instanceof DifferentialDateModifiedFieldSpecification) {
          if ($stale && $modified < $stale) {
            $days = floor((time() - $modified) / 60 / 60 / 24);
            $icons['age'] = array(
              'icon' => 'warning-grey',
              'label' => pht('Old (%d days)', $days),
            );
          } else if ($fresh && $modified < $fresh) {
            $days = floor((time() - $modified) / 60 / 60 / 24);
            $icons['age'] = array(
              'icon' => 'perflab-grey',
              'label' => pht('Stale (%d days)', $days),
            );
          } else {
            // Fresh, noOp();
          }
        }

        $rev_header = $field->renderHeaderForRevisionList();
        $rev_fields[$rev_header] = $field
          ->renderValueForRevisionList($revision);
      }

      $status = $revision->getStatus();
      $status_name =
        ArcanistDifferentialRevisionStatus::getNameForRevisionStatus($status);

      $flag_icon = null;
      if (isset($icons['flag'])) {
        $flag_icon = $icons['flag']['icon'];
      }

      $item->setObjectName('D'.$revision->getID());
      $item->setHeader(phutil_tag('a',
        array('href' => '/D'.$revision->getID()),
        $revision->getTitle()));
      $item->addAttribute($status_name);

      // Author
      $author_handle = $this->handles[$revision->getAuthorPHID()];
      $item->addByline(pht('Author: %s', $author_handle->renderLink()));

      // Reviewers
      $item->addAttribute(pht('Reviewers: %s', $rev_fields['Reviewers']));

      $item->setStateIconColumns(1);
      if ($this->highlightAge) {
        $item->setStateIconColumns(2);
        $do_not_display_age = array(
          ArcanistDifferentialRevisionStatus::CLOSED => true,
          ArcanistDifferentialRevisionStatus::ABANDONED => true,
        );
        if (isset($icons['age']) && !isset($do_not_display_age[$status])) {
          $item->addStateIcon($icons['age']['icon'], $icons['age']['label']);
        } else {
          $item->addStateIcon('none');
        }
      }

      if (isset($icons['draft'])) {
        $item->addStateIcon(
          $icons['draft']['icon'],
          pht('Saved Comments'));
      } else {
        $item->addStateIcon('none');
      }

      if ($flag_icon) {
        $item->addStateIcon($flag_icon, pht('Flagged'));
      } else {
        $item->addStateIcon('none');
      }

      // Updated on
      $item->addIcon('none', $rev_fields['Updated']);

      // First remove the fields we already have
      $count = 7;
      $rev_fields = array_slice($rev_fields, $count);

      // Then add each one of them
      // TODO: Add render-to-foot-icon support
      foreach ($rev_fields as $header => $field) {
        $item->addAttribute(pht('%s: %s', $header, $field));
      }

      $list->addItem($item);
    }

    $list->setHeader($this->header);
    $list->setNoDataString($this->noDataString);

    return $list;
  }

  public static function getDefaultFields(PhabricatorUser $user) {
    $selector = DifferentialFieldSelector::newSelector();
    $fields = $selector->getFieldSpecifications();
    foreach ($fields as $key => $field) {
      $field->setUser($user);
      if (!$field->shouldAppearOnRevisionList()) {
        unset($fields[$key]);
      }
    }

    if (!$fields) {
      throw new Exception(
        "Phabricator configuration has no fields that appear on the list ".
        "interface!");
    }

    return $selector->sortFieldsForRevisionList($fields);
  }

}
