<?php

/**
 * This file is part of the OpenPNE package.
 * (c) OpenPNE Project (http://www.openpne.jp/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file and the NOTICE file that were distributed with this source code.
 */

/**
 * message actions.
 *
 * @package    OpenPNE
 * @subpackage message
 * @author     Maki TAKAHASHI <maki@jobweb.co.jp>
 */
class opMessagePluginMessageActions extends opMessagePluginActions
{
 /**
  * Executes index action
  *
  * @param sfWebRequest $request A request object
  */
  public function executeIndex($request)
  {
    $request->setParameter('type', 'receive');
    $this->forward('message', 'list');
  }

 /**
  * Execute list action
  *
  * @param sfWebRequest $request A request object
  */
  public function executeList(sfWebRequest $request)
  {
    $this->messageType = $request->getParameter('type');
    switch ($this->messageType)
    {
      case 'receive' :
        $class = 'MessageSendListPeer';
        $function = 'getReceiveMessagePager';
        $objectName = 'MessageSendList';
        break;
      case 'send' :
        $class = 'SendMessageDataPeer';
        $function = 'getSendMessagePager';
        $objectName = 'Message';
        break;
      case 'draft' :
        $class = 'SendMessageDataPeer';
        $function = 'getDraftMessagePager';
        $objectName = 'Message';
        break;
      case 'dust' :
        $class = 'DeletedMessagePeer';
        $function = 'getDeletedMessagePager';
        $objectName = 'DeletedMessage';
        break;
      default :
        throw new LogicException();
    }

    $this->pager = call_user_func(array($class, $function), 
      $this->getUser()->getMemberId(), 
      $request->getParameter('page', 1),
      sfConfig::get('app_message_pagenatesize', 20)
    );
 
    if ($this->pager->getNbResults())
    {
      $deleteMessage = null;
      foreach ($this->pager->getResults() as $message)
      {
        $deleteMessage[] = $message->getId();
      }
      $this->form = new MessageDeleteForm(null, array('message' => $deleteMessage, 'object_name' => $objectName));
      if ($request->isMethod(sfWebRequest::POST))
      {
        $params = $request->getParameter('message');
        $this->form->bind($params);
        if ($this->form->isValid())
        {
          $this->message = $this->form->save();
          $this->redirect('@'.$this->messageType.'List');
        }
      }
    }
    else 
    {
      $this->form = null;
    }
    return sfView::SUCCESS;
  }
  
 /**
  * Executes show action
  *
  * @param sfWebRequest $request A request object
  */
  public function executeShow(sfWebRequest $request)
  {
    $this->message = SendMessageDataPeer::retrieveByPk($request->getParameter('id'));
    $this->messageType = $request->getParameter('type');
    $this->forward404unless($message = $this->isReadable($this->messageType));
    switch ($this->messageType) {
      case "receive":
        $this->deleteButton = '@deleteReceiveMessage?id='.$message->getId();
        break;
      case "send":
        $this->deleteButton = '@deleteSendMessage?id='.$this->message->getId();
        break;
      case "dust":
        $this->deleteButton = '@deleteDustMessage?id='.$message->getId();
        $this->deletedId = $message->getId();
        break;
      default :
        throw new LogicException();
    }
  }
  
 /**
  * Executes delete action
  *
  * @param sfWebRequest $request A request object
  */
  public function executeDelete(sfWebRequest $request)
  {
    $messageType = $request->getParameter('type');
    switch ($messageType) {
      case "receive":
        $objectName = 'MessageSendList';
        break;
      case "send":
        $objectName = 'Message';
        break;
      case "dust":
        $objectName = 'DeletedMessage';
        break;
      default :
        throw new LogicException();
    }
    DeletedMessagePeer::deleteMessage(sfContext::getInstance()->getUser()->getMemberId(),
                                      $request->getParameter('id'), 
                                      $objectName);
    
    $this->redirect('@'.$messageType.'List');
  }
  
 /**
  * Executes restore action
  *
  * @param sfWebRequest $request A request object
  */
  public function executeRestore(sfWebRequest $request)
  {
    DeletedMessagePeer::restoreMessage($request->getParameter('id'));
    $this->redirect('@dustList');
  }
  
 /**
  * Executes sendMessage action
  *
  * @param sfWebRequest $request A request object
  */
  public function executeSendToFriend(sfWebRequest $request)
  {
    if ($request->getParameter('message'))
    {
      $sendMemberId = $request->getParameter('message[send_member_id]');
      $this->message = SendMessageDataPeer::retrieveByPk($request->getParameter('message[id]'));
      $this->forward404Unless($this->isDraftOwner());
    }
    else if ($request->getParameter('id'))
    {
      $sendMemberId = $request->getParameter('id');
      $this->message = new SendMessageData();
    }
    else
    {
      $this->forward404();
    }

    $this->forward404If($sendMemberId == $this->getUser()->getMemberId());
    
    $this->form = new SendMessageForm($this->message, array(
      'send_member_id' => $sendMemberId
    ));
    $this->sendMember = MemberPeer::retrieveByPk($sendMemberId);

    if ($request->isMethod(sfWebRequest::POST))
    {
      $params = $request->getParameter('message');
      $this->form->bind($params, $request->getFiles('message'));

      if ($this->form->isValid())
      {
        $this->message = $this->form->save();
        if ($this->message->getIsSend())
        {
          $this->getUser()->setFlash('notice', 'The message was sent successfully.');
          $this->redirect('@sendList');
        }
        else
        {
          $this->getUser()->setFlash('notice', 'The message was saved successfully.');
          $this->redirect('@draftList');
        }
      }
    }
    return sfView::INPUT;
  }
  
 /**
  * Executes editMessage action
  * 
  * @param sfWebRequest $request A request object
  */
  public function executeEdit(sfWebRequest $request)
  {
    $this->message = SendMessageDataPeer::retrieveByPk($request->getParameter('id'));
    $this->forward404unless($this->message);
    $this->forward404Unless($this->isDraftOwner());
    if ($this->message->getMessageType() == MessageTypePeer::getMessageTypeIdByName('message')) {
      $send_list = $this->message->getSendList();
      $this->forward404Unless($send_list);
      $sendMemberId = $send_list[0]->getMember()->getId();
      $this->form = new SendMessageForm($this->message, array(
        'send_member_id' => $sendMemberId
      ));
      $this->sendMember = MemberPeer::retrieveByPk($sendMemberId);
      $this->setTemplate('sendToFriend');
      return sfView::INPUT;
    }
  }
  
 /**
  * Executes replyMessage action
  * 
  * @param sfWebRequest $request A request object
  */
  public function executeReply(sfWebRequest $request)
  {
    $message = SendMessageDataPeer::retrieveByPk($request->getParameter('id'));
    $this->forward404unless($message);
    $this->message = new SendMessageData();
    $this->message->setMessageTypeId($message->getMessageTypeId());
    $this->message->setReturnMessageId($message->getId());
    if ($message->getThreadMessageId() != 0)
    {
      $this->message->setThreadMessageId($message->getThreadMessageId());
    }
    else
    {
      $this->message->setThreadMessageId($message->getId());
    }
    $sendMemberId = $message->getMemberId();
    $this->form = new SendMessageForm($this->message, array(
      'send_member_id' => $sendMemberId
    ));
    $this->sendMember = MemberPeer::retrieveByPk($sendMemberId);
    $this->setTemplate('sendToFriend');
    return sfView::INPUT;
  }
}