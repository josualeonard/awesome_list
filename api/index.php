<?php

require 'Slim/Slim.php';
require '../medoo.min.php';

\Slim\Slim::registerAutoloader();

// Slim
$app = new \Slim\Slim();
$app->contentType("application/json");

// DB

// Deploy
$url=parse_url(getenv("CLEARDB_DATABASE_URL"));
$server = $url["host"];
$username = $url["user"];
$password = $url["pass"];
$db_name = substr($url["path"],1);

$db = new medoo(array(
  // required
  'database_type' => 'mysql',
  'database_name' => $db_name,
  'server' => $server,
  'username' => $username,
  'password' => $password,

  // optional
  'port' => 3306,
  'charset' => 'utf8',
));

// GET route
$app->get(
  '/',
  function() use ($app) {
    $template = <<<EOT
  <!DOCTYPE html>
  <html>
    <head>
      <meta charset="utf-8"/>
      <title>Slim</title>
      <style>
        html,body,
        h1,p {margin:0;padding:0;border:0;outline:0;font-size:100%;vertical-align:baseline;background:transparent;}
        body{line-height:1;}
        html{ background: #EDEDED; height: 100%; }
        body{background:#FFF;margin:0 auto;min-height:100%;padding:0 30px;width:440px;color:#666;font:14px/23px Arial,Verdana,sans-serif;}
        h1,h2,h3,p,ul,ol,form,section{margin:0 0 20px 0;}
        h1{color:#333;font-size:20px;}
        a{color:#70a23e;}
        header{padding: 30px 0;text-align:center;}
      </style>
    </head>
    <body>
      <header>
        <h1>Welcome to Awesome List API!</h1>
      </header>
      <h3>
        <a href="https://awesome-apidocs.herokuapp.com/awesome">API Docs</a>
      </h3>
    </body>
  </html>
EOT;
    $app->contentType("text/html");
    echo $template;
  }
);

// Auth
$app->post(
  '/auth',
  function() use ($db, $app){
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->post('key')){
      // get user based on key
      $db_param = array("AND" => array("session" => $app->request->post('key'), "#session_expiry[>=]" => "NOW()"));
      $auth = $db->select("user", array("id","username","session_expiry"), $db_param);

      // check it's session expiry, if not expired return success and extend session expiry
      if(count($auth)>0) {
        $id = $auth[0]['id'];
        $date = new DateTime();
        $date->modify('+30 day');
        $db->update("user", array("session_expiry" => $date->format('Y-m-d H:i:s')), array("id" => $id));
		$result["result"] = array(
			"user_id" => $id
		);
      }
      // if expired return fail
      else {
        $result['status'] = 0;
        $result['message'] = 'Invalid key or session expired, please login to regain access';
      }
    }
    else if($app->request->post('username') && $app->request->post('password')){
      // get user based on username and password
      $db_param = array("AND" => array("username" => $app->request->post('username'), "password" => md5($app->request->post('password'))));
      $auth = $db->select("user", array("id","username"), $db_param);

      // if success, create key, set session expiry 30 days, return success and key
      if(count($auth)>0) {
        $id = $auth[0]['id'];
        $username = $auth[0]['username'];
        $date = new DateTime();
        $session = md5($username."-".$date->format('Y-m-d H:i:s'));
        $date->modify('+30 day');
        $db->update("user", array("session" => $session, "session_expiry" => $date->format('Y-m-d H:i:s')), array("id" => $id));
        $result['status'] = 1;
        $result['key'] = $session;
		$result['user_id'] = $id;
      }
      // if failed, return fail code
      else {
        $result['status'] = 0;
        $result['message'] = 'Invalid username or password, you can always reset your password';
      }
    }
    else {
      $result['status'] = 0;
      $result['message'] = 'Authentication failed';
    }
    echo json_encode($result);
  }
);

/**
 * Membership
 */

// Get users
$app->get(
  '/users',
  function() use ($db, $app){
    $result = array('status' => 1, 'message' => 'Success');
    // get user based on key
    $db_param = array("OR" => array("deactivated #first_cond" => "0000-00-00 00:00:00", "deactivated #second_cond" => null));
    $users = $db->select("user", "*", $db_param);
    // check it's session expiry, if not expired return success and extend session expiry
    if(is_array($users)) {
        if(count($users)>0){
            foreach($users as $key=>$user){
                if(!$users["deactivated"] || $users["deactivated"]=="0000-00-00 00:00:00"){
                    $users[$key]["deactivated"] = "";
                }
            }
        }
      $result['result'] = array(
        'users' => $users,
        'length' => count($users)
      );
    }
    // if expired return fail
    else {
      $result['status'] = 0;
      $result['message'] = 'Failed to get users';
    }
    echo json_encode($result);
  }
);

// Get user
$app->get(
  '/account',
  function() use ($db, $app){
    $result = array("status"=>1, "message"=>"Success");
    if($app->request->get('key')){
      $db_param = array("session" => $app->request->get('key'));
      $user = $db->select("user", array("id","username","firstname","lastname","email","password","company","location","photo","timeline_photo","registered"), $db_param);
      if(count($user)>0){
        $query = "SELECT f.id, f.friend_id, u.username FROM user u, friendship f WHERE f.friend_id=u.id AND f.my_id={$user[0]['id']}";
        $qres = $db->query($query);
        $result1 = $qres->fetchAll();
        $query = "SELECT f.id, f.my_id, u.username FROM user u, friendship f WHERE f.my_id=u.id AND f.friend_id={$user[0]['id']}";
        $qres = $db->query($query);
        $result2 = $qres->fetchAll();

        $user_friends = array();
        if(count($result1)>0){
          foreach($result1 as $r){
            array_push($user_friends, array(
              'id' => $r['id'],
              'friend_id' => $r['friend_id'],
              'username' => $r['username']
            ));
          }
        }
        if(count($result2)>0){
          foreach($result2 as $r){
            array_push($user_friends, array(
              'id' => $r['id'],
              'friend_id' => $r['my_id'],
              'username' => $r['username']
            ));
          }
        }

        $result["result"] = array(
          "user" => array(
            "id" => $user[0]['id'],
            "username" => $user[0]['username'],
            "firstname" => $user[0]['firstname'],
            "lastname" => $user[0]['lastname'],
            "email" => $user[0]['email'],
            "password" => $user[0]['password'],
            "company" => $user[0]['company'],
            "location" => $user[0]['location'],
            "photo" => $user[0]['photo'],
            "timeline_photo" => $user[0]['timeline_photo'],
            "registered" => $user[0]['registered']
          ),
          "friends" => $user_friends,
          "length" => count($user_friends)
        );
      } else {
        $result["status"] = 0;
        $result["message"] = "Invalid key";
      }
    }
    else {
      $result["status"] = 0;
      $result["message"] = "Invalid parameter";
    }
    echo json_encode($result);
  }
);

// Register
$app->post(
  '/account',
  function() use ($db, $app){
    $result = array("status"=>1, "message"=>"Success");
    if($app->request->post('username') && $app->request->post('firstname') && $app->request->post('email') && $app->request->post('password')){
      // Check for existing record
      $db_param = array("OR" => array("username" => $app->request->post('username'), "email" => $app->request->post('email')));
      $exist = $db->select("user", array("id","username","email"), $db_param);
      // Add new user
      if(count($exist)<=0){
        $date = new DateTime();
        $session = md5($app->request->post('username')."-".$date->format('Y-m-d H:i:s'));
        $date->modify('+30 day');
        $db_result = $db->insert('user', array(
          'username' => $app->request->post('username'),
          'firstname' => stripslashes($app->request->post('firstname')),
          'lastname' => stripslashes($app->request->post('lastname')),
          'email' => stripslashes($app->request->post('email')),
          'password' => md5($app->request->post('password')),
          'session' => $session,
          'session_expiry' => $date->format('Y-m-d H:i:s'),
          'company' => stripslashes($app->request->post('company')),
          'location' => stripslashes($app->request->post('location'))
        ));
        if($db_result>0){
          $result["id"] = $db_result;
          $result["key"] = $session;
        }
        else {
          $result["status"] = 0;
          $result["message"] = 'Failed to create new user';
        }
      }
      // Failed
      else {
        $result["status"] = 0;
        $username_exist = ($exist[0]['username']==$app->request->post('username'));
        $email_exist = ($exist[0]['email']==$app->request->post('email'));
        $result["message"] = 'User with this ';
        if($username_exist){
          $result["message"] .= 'username';
        }
        if($username_exist && $email_exist){
          $result["message"] .= ' and ';
        }
        if($email_exist){
          $result["message"] .= 'email';
        }
        $result["message"] .= ' already registered';
      }
    }
    echo json_encode($result);
  }
);

// Update
$app->put(
  '/account',
  function() use ($db, $app) {
    $result = array("status" => 1, "message" => "Success");
    if($app->request->put('key')){
      $db_param = array("session" => $app->request->put('key'));
      $user = $db->select("user", array("id","firstname","lastname","email","password","company","location"), $db_param);
      if(count($user)>0){
        $id = $user[0]['id'];
        $update = array();
        if($app->request->put('firstname') && $user[0]['firstname']!=$app->request->put('firstname')){
          $update["firstname"] = $app->request->put('firstname');
        }
        if($app->request->put('lastname') && $user[0]['lastname']!=$app->request->put('lastname')){
          $update["lastname"] = $app->request->put('lastname');
        }
        $valid = true;
        if($app->request->put('email') && $user[0]['email']!=$app->request->put('email')){
          $db_param = array("email" => $app->request->put('email'));
          $record = $db->select("user", array("id"), $db_param);
          if(count($record)>0 && $record[0]['id']!=$id){
            $valid = false;
            $result["status"] = 0;
            $result["message"] = "Email already registered to another user";
          }
        }
        if($app->request->put('password') && $user[0]['password']!=md5($app->request->put('password'))){
          $update["password"] = md5($app->request->put('password'));
        }
        if($app->request->put('company') && $user[0]['company']!=$app->request->put('company')){
          $update["company"] = $app->request->put('company');
        }
        if($app->request->put('location') && $user[0]['location']!=$app->request->put('location')){
          $update["location"] = $app->request->put('location');
        }
        if($valid && count($update)>0){
          $db_result = $db->update("user", $update, array("id" => $id));
          if($db_result>0){}
          else {
            $result["status"] = 0;
            $result["message"] = "Failed to update user";
          }
        }
        else if($valid && count($update)<=0){
          $result["status"] = 0;
          $result["message"] = "Nothing to update";
        }
      } else {
        $result["status"] = 0;
        $result["message"] = "Invalid key";
      }
    }
    else {
      $result["status"] = 0;
      $result["message"] = "Invalid parameter";
    }
    echo json_encode($result);
  }
);

$app->post(
  '/profile_photo',
  function() use ($db, $app){
    $result = array('status' => 1, 'message' => 'Success');
    //var_dump($_POST);
    //var_dump($_FILES);

    if($app->request->post('key')){
      // get user based on key
      $db_param = array("AND" => array("session" => $app->request->post('key'), "#session_expiry[>=]" => "NOW()"));
      $auth = $db->select("user", array("id","username","session_expiry"), $db_param);

      // check it's session expiry, if not expired return success and extend session expiry
      if(count($auth)>0){
        if(isset($_FILES['media']['tmp_name'][0]) && $_FILES['media']['tmp_name'][0]!=''){
          try {
            if(!is_dir("./uploads/{$auth[0]['username']}")) mkdir("./uploads/{$auth[0]['username']}");
            $upload = move_uploaded_file($_FILES['media']['tmp_name'][0], "./uploads/{$auth[0]['username']}/profile_photo.jpg");
            if($upload){
              $db->update("user", array("photo" => "https://{$_SERVER["HTTP_HOST"]}/api/uploads/{$auth[0]['username']}/profile_photo.jpg"), array("id" => $auth[0]['id']));
            } else {
              $result['status'] = 0;
              $result['message'] = "Could not move uploaded file";
            }
          } catch(Exception $e){
            $result['status'] = 0;
            $result['message'] = $e->getMessage();
          }
        } else {
          $result['status'] = 0;
          $result['message'] = "File not uploaded";
        }
      }
      // if expired return fail
      else {
        $result['status'] = 0;
        $result['message'] = 'Invalid key or session expired, please login to regain access';
      }
    }
    else {
      $result['status'] = 0;
      $result['message'] = 'Authentication failed';
    }
    echo json_encode($result);
  }
);

$app->post(
  '/timeline_photo',
  function() use ($db, $app){
    $result = array('status' => 1, 'message' => 'Success');

    if($app->request->post('key')){
      // get user based on key
      $db_param = array("AND" => array("session" => $app->request->post('key'), "#session_expiry[>=]" => "NOW()"));
      $auth = $db->select("user", array("id","username","session_expiry"), $db_param);

      // check it's session expiry, if not expired return success and extend session expiry
      if(count($auth)>0){
        if(isset($_FILES['media']['tmp_name'][0]) && $_FILES['media']['tmp_name'][0]!=''){
          try {
            if(!is_dir("./uploads/{$auth[0]['username']}")) mkdir("./uploads/{$auth[0]['username']}");
            $upload = move_uploaded_file($_FILES['media']['tmp_name'][0], "./uploads/{$auth[0]['username']}/timeline_photo.jpg");
            if($upload){
              $db->update("user", array("timeline_photo" => "https://{$_SERVER["HTTP_HOST"]}/api/uploads/{$auth[0]['username']}/timeline_photo.jpg"), array("id" => $auth[0]['id']));
            } else {
              $result['status'] = 0;
              $result['message'] = "Could not move uploaded file";
            }
          } catch(Exception $e){
            $result['status'] = 0;
            $result['message'] = $e->getMessage();
          }
        } else {
          $result['status'] = 0;
          $result['message'] = "File not uploaded";
        }
      }
      // if expired return fail
      else {
        $result['status'] = 0;
        $result['message'] = 'Invalid key or session expired, please login to regain access';
      }
    }
    else {
      $result['status'] = 0;
      $result['message'] = 'Authentication failed';
    }
    echo json_encode($result);
  }
);

// Deactivate
$app->delete(
  '/account',
  function() use ($db, $app){
    $result = array("status" => 1, "message" => "Success");
    if($app->request->get('key')){
      $db_param = array("session" => $app->request->get('key'));
      $user = $db->select("user", array("id"), $db_param);
      if(count($user)>0){
        $date = new DateTime();
        $db->update("user", array("deactivated" => $date->format('Y-m-d H:i:s')), array("id" => $user[0]['id']));
      } else {
        $result["status"] = 0;
        $result["message"] = "Invalid key";
      }
    } else {
      $result["status"] = 0;
      $result["message"] = "Invalid parameter";
    }
    echo json_encode($result);
  }
);

/**
 * Tasks
 */

// Get Task
$app->get(
  '/tasks/:id',
  function ($id) use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->get('key')){
      $db_param = array("session"=>$app->request->get('key'));
      $user = $db->select("user", array("id","username","deactivated"), $db_param);
      if(count($user)>0){
        if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
          //$db_param = array("AND" => array("user_id"=>$user[0]['id'], "public"=>1));
          if(($id!="{id}" || $id!="") && $id>0){
            $db_param = array("AND" => array("id"=>$id, "user_id"=>$user[0]['id']));
            $tasks = $db->select("view_user_tasks", "*", $db_param);
            if(is_array($tasks) && count($tasks)>0){
                if(!$tasks[0]["photo"]){
                    $tasks[0]["photo"] = "";
                }
                if(!$tasks[0]["modified"]){
                    $tasks[0]["modified"] = $tasks[0]["created"];
                }
                $result['result'] = array(
                  'length' => 1,
                  'tasks' => $tasks[0]
                );
            } else {
                $result['result'] = array(
                    'length' => 0, 'tasks' => array()
                );
            }
          }
          else {
            $db_param = array("user_id"=>$user[0]['id']);
            $tasks = $db->select("view_user_tasks", "*", $db_param);
            if(count(tasks)>0 && is_array($tasks)){
                foreach($tasks as $key=>$task){
                    if(!$task["photo"]){
                        $tasks[$key]["photo"] = "";
                    }
                    if(!$task["modified"]){
                        $tasks[$key]["modified"] = $tasks[$key]["created"];
                    }
                }
            } else { $tasks = array(); }
            $result['result'] = array(
              'user_id' => $user[0]['id'],
              'username' => $user[0]['username'],
              'length' => count($tasks),
              'tasks' => $tasks
            );
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'User has been deactivated';
        }
      } else {
        $result['status'] = 0;
        $result['message'] = 'Session invalid';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

$app->get(
  '/tasks',
  function () use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->get('key')){
      $db_param = array("session"=>$app->request->get('key'));
      $user = $db->select("user", array("id","username","deactivated"), $db_param);
      if(count($user)>0){
        if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
          $db_param = array("user_id"=>$user[0]['id']);
          $tasks = $db->select("view_user_tasks", "*", $db_param);
          if(count(tasks)>0 && is_array($tasks)){
              foreach($tasks as $key=>$task){
                  if(!$task["photo"]){
                      $tasks[$key]["photo"] = "";
                  }
                  if(!$task["modified"]){
                      $tasks[$key]["modified"] = $tasks[$key]["created"];
                  }
              }
          } else { $tasks = array(); }
          $result['result'] = array(
            'user_id' => $user[0]['id'],
            'username' => $user[0]['username'],
            'length' => count($tasks),
            'tasks' => $tasks
          );
        } else {
          $result['status'] = 0;
          $result['message'] = 'User has been deactivated';
        }
      } else {
        $result['status'] = 0;
        $result['message'] = 'Session invalid';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

// Create Task (with upload)
$app->post(
  '/tasks',
  function () use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->post('key')){
      $db_param = array("session"=>$app->request->post('key'));
      $user = $db->select("user", array("id","username","deactivated"), $db_param);
      if(count($user)>0){
        if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
          if($app->request->post('title') && $app->request->post('desc')){
            $photo = false;
            $photo_uploaded = false;
            if(isset($_FILES['media']['tmp_name'][0]) && $_FILES['media']['tmp_name'][0]!=''){
              $photo = true;
              try {
                if(!is_dir("./uploads/{$user[0]['username']}")) mkdir("./uploads/{$user[0]['username']}");
                $upload = move_uploaded_file($_FILES['media']['tmp_name'][0], "./uploads/{$user[0]['username']}/new_task_tmp.jpg");
                if($upload){
                  $photo_uploaded = true;
                } else {
                  $result['status'] = 0;
                  $result['message'] = "Could not move uploaded file";
                }
              } catch(Exception $e){
                $result['status'] = 0;
                $result['message'] = $e->getMessage();
              }
            }

            if(!$photo || ($photo && $photo_uploaded)){
              $insert = array(
                'user_id' => $user[0]['id'],
                'title' => stripslashes($app->request->post('title')),
                'desc' => stripslashes($app->request->post('desc')),
                'public' => intval(($app->request->post('public'))?$app->request->post('public'):1),
                'done' => intval(($app->request->post('done'))?$app->request->post('done'):0),
                'due' => stripslashes($app->request->post('due')),
                'location' => stripslashes($app->request->post('location'))
              );
              $db_result = $db->insert('task', $insert);
              if($db_result>0){
                $result['id'] = $db_result;
                if($photo) {
                  $photo = "https://{$_SERVER["HTTP_HOST"]}/api/uploads/{$user[0]['username']}/task_{$result['id']}.jpg";
                  rename("./uploads/{$user[0]['username']}/new_task_tmp.jpg", "./uploads/{$user[0]['username']}/task_{$result['id']}.jpg");
                  $db->update("task", array("photo" => $photo), array("id" => $result['id']));
                }
                $db_param = array('id' => $result['id']);
                $tasks = $db->select("view_user_tasks", "*", $db_param);
                if(count(tasks)>0 && is_array($tasks)){
                    foreach($tasks as $key=>$task){
                        if(!$task["photo"]){
                            $tasks[$key]["photo"] = "";
                        }
                        if(!$task["modified"]){
                            $tasks[$key]["modified"] = $tasks[$key]["created"];
                        }
                    }
                } else { $tasks = array(); }
                $result['result'] = $tasks[0];
              }
              else {
                $result["status"] = 0;
                $result["message"] = 'Failed to add new task';
              }
            }
          }
          else {
            $result["status"] = 0;
            $result["message"] = 'Title and description are mandatory';
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'User has been deactivated';
        }
      } else {
        $result['status'] = 0;
        $result['message'] = 'Session invalid';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

// Edit Task - Without Upload (PUT method does not support upload file)
$app->put(
  '/tasks/:id',
  function ($id) use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->post('key')){
      if($id!='' && $id!='{id}'){
        $db_param = array("session"=>$app->request->post('key'));
        $user = $db->select("user", array("id","id"), $db_param);
        if(count($user)>0){
          if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
            $task = $db->select("task", array("id","title","desc","public","done","due","location"), array("AND" => array("id"=>$id, "user_id"=>$user[0]['id'])));
            if(count($task)>0){
              $update = array();
              if($app->request->post('title') && $task[0]['title']!=$app->request->post('title')){
                $update['title'] = stripslashes($app->request->post('title'));
              }
              if($app->request->post('desc') && $task[0]['desc']!=$app->request->post('desc')){
                $update['desc'] = stripslashes($app->request->post('desc'));
              }
              if($app->request->post('public')!==null && $task[0]['public']!=$app->request->post('public')){
                $update['public'] = intval($app->request->post('public'));
              }
              if($app->request->post('done')!==null && $task[0]['done']!=$app->request->post('done')){
                $update['done'] = intval($app->request->post('done'));
              }
              if($app->request->post('due') && $task[0]['due']!=$app->request->post('due')){
                $update['due'] = stripslashes($app->request->post('due'));
              }
              if($app->request->post('location') && $task[0]['location']!=$app->request->post('location')){
                $update['location'] = stripslashes($app->request->post('location'));
              }
              if(count($update)>0){
                $update['modified'] = date("Y-m-d H:i:s");
                $db_result = $db->update('task', $update, array("id"=>$id));
                if($db_result>0){
                    $db_param = array('id' => $id);
                    $tasks = $db->select("view_user_tasks", "*", $db_param);
                    if(is_array($tasks) && count(tasks)>0){
                        foreach($tasks as $key=>$task){
                            if(!$task["photo"]){
                                $tasks[$key]["photo"] = "";
                            }
                        }
                    } else { $tasks = array(); }
                    $result['result'] = $tasks[0];
                }
                else {
                  $result["status"] = 0;
                  $result["message"] = 'Failed to update task';
                }
              }
              else {
                $result["status"] = 0;
                $result["message"] = 'Nothing to update';
              }
            }
            else {
              $result["status"] = 0;
              $result["message"] = 'Task not found';
            }
          } else {
            $result['status'] = 0;
            $result['message'] = 'User has been deactivated';
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'Session invalid';
        }
      }
      else {
        $result['status'] = 0;
        $result['message'] = 'No task specified';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

// Edit Task With Media (with upload)
$app->post(
  '/tasks_with_media/:id',
  function ($id) use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->post('key')){
      if($id!='' && $id!='{id}'){
        $db_param = array("session"=>$app->request->post('key'));
        $user = $db->select("user", array("id","username","deactivated"), $db_param);
        if(count($user)>0){
          if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
            $task = $db->select("task", array("id","title","desc","public","done","due","location"), array("AND" => array("id"=>$id, "user_id"=>$user[0]['id'])));
            if(count($task)>0){
              $update = array();
              $photo = false;
              $photo_uploaded = false;
              if(isset($_FILES['media']['tmp_name'][0]) && $_FILES['media']['tmp_name'][0]!=''){
                $photo = true;
                try {
                  if(!is_dir("./uploads/{$user[0]['username']}")) mkdir("./uploads/{$user[0]['username']}");
                  $upload = move_uploaded_file($_FILES['media']['tmp_name'][0], "./uploads/{$user[0]['username']}/task_{$id}.jpg");
                  if($upload){
                    $photo_uploaded = true;
                  } else {
                    $result['status'] = 0;
                    $result['message'] = "Could not move uploaded file";
                  }
                } catch(Exception $e){
                  $result['status'] = 0;
                  $result['message'] = $e->getMessage();
                }
              }

              if(!$photo || ($photo && $photo_uploaded)) {
                if ($app->request->post('title') && $task[0]['title'] != $app->request->post('title')) {
                  $update['title'] = stripslashes($app->request->post('title'));
                }
                if ($app->request->post('desc') && $task[0]['desc'] != $app->request->post('desc')) {
                  $update['desc'] = stripslashes($app->request->post('desc'));
                }
                if ($app->request->post('public') !== null && $task[0]['public'] != $app->request->post('public')) {
                  $update['public'] = intval($app->request->post('public'));
                }
                if ($app->request->post('done') !== null && $task[0]['done'] != $app->request->post('done')) {
                  $update['done'] = intval($app->request->post('done'));
                }
                if ($app->request->post('due') && $task[0]['due'] != $app->request->post('due')) {
                  $update['due'] = stripslashes($app->request->post('due'));
                }
                if ($app->request->post('location') && $task[0]['location'] != $app->request->post('location')) {
                  $update['location'] = stripslashes($app->request->post('location'));
                }
                if($photo){
                  $update['photo'] = "https://{$_SERVER["HTTP_HOST"]}/api/uploads/{$user[0]['username']}/task_{$id}.jpg";
                }
                if (count($update) > 0) {
                  $update['modified'] = date("Y-m-d H:i:s");
                  $db_result = $db->update('task', $update, array("id" => $id));
                  if ($db_result > 0) {
                      $db_param = array('id' => $id);
                      $tasks = $db->select("view_user_tasks", "*", $db_param);
                      if(count(tasks)>0 && is_array($tasks)){
                          foreach($tasks as $key=>$task){
                              if(!$task["photo"]){
                                  $tasks[$key]["photo"] = "";
                              }
                          }
                      } else { $tasks = array(); }
                      $result['result'] = $tasks[0];
                  } else {
                    $result["status"] = 0;
                    $result["message"] = 'Failed to update task';
                  }
                } else {
                  $result["status"] = 0;
                  $result["message"] = 'Nothing to update';
                }
              }
            }
            else {
              $result["status"] = 0;
              $result["message"] = 'Task not found';
            }
          } else {
            $result['status'] = 0;
            $result['message'] = 'User has been deactivated';
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'Session invalid';
        }
      }
      else {
        $result['status'] = 0;
        $result['message'] = 'No task specified';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

// Delete Task
$app->delete(
  '/tasks/:id',
  function($id) use ($db, $app){
    $result = array("status" => 1, "message" => "Success");
    if($app->request->get('key')){
      if($id!='' && $id!='{id}'){
        $db_param = array("session"=>$app->request->get('key'));
        $user = $db->select("user", array("id","deactivated"), $db_param);
        if(count($user)>0){
          if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
            $task = $db->select("task", array("id","title","desc","public","done","due","location"), array("AND" => array("id"=>$id, "user_id"=>$user[0]['id'])));
            if(count($task)>0){
              $db_result = $db->delete('task', array("id"=>$id));
              if(is_file("./"))
              if($db_result>0){}
              else {
                $result["status"] = 0;
                $result["message"] = 'Failed to delete task';
              }
            }
            else {
              $result["status"] = 0;
              $result["message"] = 'Task not found';
            }
          } else {
            $result['status'] = 0;
            $result['message'] = 'User has been deactivated';
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'Session invalid';
        }
      }
      else {
        $result['status'] = 0;
        $result['message'] = 'No task specified';
      }
    } else {
      $result["status"] = 0;
      $result["message"] = "Invalid parameter";
    }
    echo json_encode($result);
  }
);

/**
 * Friendship
 */

// Get friends
$app->get(
  '/friendship',
  function() use ($db, $app){
    $result = array("status"=>1, "message"=>"Success");
    if($app->request->get('key')){
      $db_param = array("session" => $app->request->get('key'));
      $user = $db->select("user", array("id","username","firstname","lastname","email","password","company","location","photo","timeline_photo","registered"), $db_param);
      if(count($user)>0){
        $query = "SELECT f.id, f.friend_id, u.username FROM user u, friendship f WHERE f.friend_id=u.id AND f.my_id={$user[0]['id']}";
        $qres = $db->query($query);
        $result1 = $qres->fetchAll();
        $query = "SELECT f.id, f.my_id, u.username FROM user u, friendship f WHERE f.my_id=u.id AND f.friend_id={$user[0]['id']}";
        $qres = $db->query($query);
        $result2 = $qres->fetchAll();

        $user_friends = array();
        if(count($result1)>0){
          foreach($result1 as $r){
            array_push($user_friends, array(
              'id' => $r['id'],
              'friend_id' => $r['friend_id'],
              'username' => $r['username']
            ));
          }
        }
        if(count($result2)>0){
          foreach($result2 as $r){
            array_push($user_friends, array(
              'id' => $r['id'],
              'friend_id' => $r['my_id'],
              'username' => $r['username']
            ));
          }
        }
        $result["result"] = array(
          "friends" => $user_friends,
          "length" => count($user_friends)
        );
      } else {
        $result["status"] = 0;
        $result["message"] = "Invalid key";
      }
    }
    else {
      $result["status"] = 0;
      $result["message"] = "Invalid parameter";
    }
    echo json_encode($result);
  }
);

// Connect
$app->post(
  '/friendship/:with',
  function ($with) use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->post('key')){
      if($with!='' && $with!='{with}'){
        $db_param = array("session"=>$app->request->post('key'));
        $user = $db->select("user", array("id","username","deactivated"), $db_param);
        if(count($user)>0){
          if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
            if($user[0]['username']!=$with){
              $friend_with = $db->select("user", array("id","username","deactivated"), array("username"=>$with));
              if(count($friend_with)>0){
                if(!isset($friend_with[0]['deactivated']) || $friend_with[0]['deactivated']=="0000-00-00 00:00:00"){
                  $new_friend = $db->select("friendship", "*", array(
                    "OR" => array(
                      "AND #first_cond" => array(
                        "my_id" => $user[0]['id'],
                        "friend_id" => $friend_with[0]['id']
                      ),
                      "AND #second_cond" => array(
                        "my_id" => $friend_with[0]['id'],
                        "friend_id" => $user[0]['id']
                      )
                    )
                  ));
                  if(is_array($new_friend) && count($new_friend)<=0){
                    $new_friend = $db->insert("friendship", array(
                      "my_id" => $user[0]['id'],
                      "friend_id" => $friend_with[0]['id']
                    ));
                    if($new_friend>0){
	                  $result["result"] = array(
	                  	"id" => $new_friend,
	                    "friend_id" => intval($friend_with[0]['id']),
                        "username" => $friend_with[0]['username'],
	                    "friends" => 0
	                  );
					  $friend_with = $db->select("view_friendship_summary", array("my_id","username","friends"), array("username"=>$user[0]['username']));
					  if(is_array($friend_with)) {
					    $result["result"]["friends"] = intval(isset($friend_with[0]['friends']) ? $friend_with[0]['friends'] : 0);
				      }
                    }
                    else {
                      $result["status"] = 0;
                      $result["message"] = 'Failed to connect with '.strip_tags($with);
                    }
                  }
                  else {
                    $result["status"] = 0;
                    $result["message"] = 'Already friend with '.strip_tags($with);
                  }
                }
                else {
                  $result['status'] = 0;
                  $result['message'] = 'The friend you\'re trying to connect is not active';
                }
              }
              else {
                $result['status'] = 0;
                $result['message'] = 'Friend with that username did not exist';
              }
            }
            else {
              $result['status'] = 0;
              $result['message'] = 'Friend username should be different';
            }
          } else {
            $result['status'] = 0;
            $result['message'] = 'User has been deactivated';
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'Session invalid';
        }
      }
      else {
        $result['status'] = 0;
        $result['message'] = 'Invalid friend username';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

// Unfriend/disconnect friendship
$app->delete(
  '/friendship/:with',
  function ($with) use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->get('key')){
      if($with!='' && $with!='{with}'){
        $db_param = array("session"=>$app->request->get('key'));
        $user = $db->select("user", array("id","username","deactivated"), $db_param);
        if(count($user)>0){
          if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
            if($user[0]['username']!=$with){
              $friend_with = $db->select("user", array("id","deactivated"), array("username"=>$with));
              if(count($friend_with)>0){
                $new_friend = $db->select("friendship", "*", array(
                  "OR" => array(
                    "AND #first_cond" => array(
                      "my_id" => $user[0]['id'],
                      "friend_id" => $friend_with[0]['id']
                    ),
                    "AND #second_cond" => array(
                      "my_id" => $friend_with[0]['id'],
                      "friend_id" => $user[0]['id']
                    )
                  )
                ));
                if(is_array($new_friend) && count($new_friend)>0){
                  $del_id = array();
                  foreach($new_friend as $nf){
                    array_push($del_id, $nf['id']);
                  }
                  $delete_friend = $db->delete("friendship", array("id" => $del_id));
                  if($delete_friend){
	                $result["result"] = array(
	                  "unfriend_id" => intval($friend_with[0]['id']),
	                  "friends" => 0
	                );
                    $friend_with = $db->select("view_friendship_summary", array("my_id","username","friends"), array("username"=>$user[0]['username']));
					if(is_array($friend_with)) {
					  $result["result"]["friends"] = intval(isset($friend_with[0]['friends']) ? $friend_with[0]['friends'] : 0);
				    }
                  }
                }
                else {
                  $result["status"] = 0;
                  $result["message"] = 'Not connected with '.strip_tags($with);
                }
              }
              else {
                $result['status'] = 0;
                $result['message'] = 'Friend with that username did not exist';
              }
            }
            else {
              $result['status'] = 0;
              $result['message'] = 'Friend username should be different';
            }
          } else {
            $result['status'] = 0;
            $result['message'] = 'User has been deactivated';
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'Session invalid';
        }
      }
      else {
        $result['status'] = 0;
        $result['message'] = 'Invalid friend username';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

// Stats
$app->get(
  '/friendship/:username',
  function($username) use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->get('key')){
      $db_param = array("session"=>$app->request->get('key'));
      $user = $db->select("user", array("id","username","deactivated"), $db_param);
      if(count($user)>0){
        if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
          $friend_of = $user[0]['username'];
          if(!in_array($username, array('','{username}'))) {
            $friend_of = $username;
          }
          $friend_with = $db->select("view_friendship_summary", array("my_id","username","friends"), array("username"=>$friend_of));
          if(is_array($friend_with)) {
            $result['result'] = array('friendship' => array(
              'username' => $friend_of,
              'friends' => isset($friend_with[0]['friends']) ? $friend_with[0]['friends'] : 0
            ));
          } else {
            $result["status"] = 0;
            $result["message"] = 'Failed to get friend list';
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'User has been deactivated';
        }
      } else {
        $result['status'] = 0;
        $result['message'] = 'Invalid user';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

// Stats
$app->get(
  '/friendship',
  function() use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->get('key')){
      $db_param = array("session"=>$app->request->get('key'));
      $user = $db->select("user", array("id","username","deactivated"), $db_param);
      if(count($user)>0){
        if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
          $friend_of = $user[0]['username'];
          $friend_with = $db->select("view_friendship_summary", array("my_id","username","friends"), array("username"=>$friend_of));
          if(is_array($friend_with)) {
            $result['friendship'] = array(
              'username' => $friend_of,
              'friends' => isset($friend_with[0]['friends']) ? $friend_with[0]['friends'] : 0
            );
          } else {
            $result["status"] = 0;
            $result["message"] = 'Failed to get friend list';
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'User has been deactivated';
        }
      } else {
        $result['status'] = 0;
        $result['message'] = 'Invalid user';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

/**
 * Misc
 */

// Dashboard
$app->get(
  '/dashboard',
  function() use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->get('key')){
      $db_param = array("session"=>$app->request->get('key'));
      $user = $db->select("user", array("id","username","deactivated"), $db_param);
      if(count($user)>0){
        if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
          $id = $user[0]['id'];
          $friend_ids = array();
          $friends = $db->select("friendship", array("id","my_id","friend_id","unfriend"), array("AND" => array("OR" => array("my_id"=>$id, "friend_id"=>$id), "unfriend"=>0)));
          $tasks = array();
          if(is_array($friends) && $friends>0){
              foreach($friends as $f){
                  if($f['my_id']==$id){
                      array_push($friend_ids, $f['friend_id']);
                  } else {
                      array_push($friend_ids, $f['my_id']);
                  }
              }
              $friend_ids = array_unique($friend_ids);
              $tasks = $db->select("view_user_tasks", "*", array("user_id" => $friend_ids, "LIMIT" => 10));
              if(is_array($tasks) && count($tasks)>0){
                  foreach($tasks as $key=>$task){
                      if(!$task["photo"]){
                          $tasks[$key]["photo"] = "";
                      }
                      if(!$task["modified"]){
                          $tasks[$key]["modified"] = $tasks[$key]["created"];
                      }
                  }
              } else {
                  $tasks = array();
              }
          }
          $result['result'] = array(
            'tasks' => $tasks,
            'length' => count($tasks)
          );
        } else {
          $result['status'] = 0;
          $result['message'] = 'User has been deactivated';
        }
      } else {
        $result['status'] = 0;
        $result['message'] = 'Invalid user';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

// Task stats
$app->get(
  '/stats',
  function() use ($db, $app) {
    $result = array('status' => 1, 'message' => 'Success');
    if($app->request->get('key')){
      $db_param = array("session"=>$app->request->get('key'));
      $user = $db->select("user", array("id","username","deactivated"), $db_param);
      if(count($user)>0){
        if(!isset($user[0]['deactivated']) || $user[0]['deactivated']=="0000-00-00 00:00:00"){
          $id = $user[0]['id'];
          $stats = $db->select("view_task_summary", array("user_id","username","total_tasks","week_tasks","week_done","month_tasks","month_done"), array("user_id" => $id));
          if(is_array($stats)) {
            $result['result'] = array('stats' => $stats[0]);
          } else {
            $result["status"] = 0;
            $result["message"] = 'Failed to get task stats';
          }
        } else {
          $result['status'] = 0;
          $result['message'] = 'User has been deactivated';
        }
      } else {
        $result['status'] = 0;
        $result['message'] = 'Invalid user';
      }
    } else {
      $result['status'] = 0;
      $result['message'] = 'You should be logged in to gain access';
    }
    echo json_encode($result);
  }
);

/**
 * Run the Slim app
 *
 * This method should be called last. This executes the Slim application
 * and returns the HTTP response to the HTTP client.
 */
$app->run();
