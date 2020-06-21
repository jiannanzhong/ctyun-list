<?php

namespace ctCloud\app\controller\user;

use ctCloud\app\common\Base32;
use ctCloud\app\common\CtCloudHelper;
use Slim\Http\Request;
use Slim\Http\Response;
use ctCloud\app\mod\D;

class ControllerDir
{
    public function download(Request $req, Response $rsp, array $args)
    {
        $token = Base32::decrypt(base64_decode($args['token']));
        $fileId = $args['file_id'];
        session_id($token);
        session_start();
        if ($_SESSION['user'] === null) {
            session_destroy();
            session_register_shutdown();
            session_write_close();
            return $rsp->withStatus(401);
        }
        $GLOBALS['session'] = $_SESSION;
        session_write_close();

        $d = D::getInstance();
        $fileInfo = [];
        try {
            $fileInfo = $d->getDirListById([
                'id', 'name', 'user_id', 'disk_file_name', 'file_length', 'ct_cloud_download_link', 'ct_link_last_update'
            ], $fileId);
        } catch (\Exception $e) {
        }
        if (empty($fileInfo) || $fileInfo['user_id'] != $GLOBALS['session']['user']['id']) {
            return $rsp->withStatus(404);
        }

//        return $rsp->withJson([
//            'token' => $token,
//            'file_id' => $fileId,
//            'file_info' => $fileInfo
//        ]);
        $realLink = '';
        if (!empty($fileInfo['ct_cloud_download_link']) && time() - $fileInfo['ct_link_last_update'] < 200) {
            $realLink = $fileInfo['ct_cloud_download_link'];
            $d->updateDirInfoById(['ct_link_last_update' => time()], $fileId);
        } else {
            $tryCount = 0;
            while ($tryCount++ < 3 && empty($realLink)) {
                $realLink = file_get_contents(
                    'https://domain.com:port/get_real_link?fileRecord='
                    . $fileInfo['disk_file_name']
                    . '&authCode=ct-cloud'
                );
            }
            if (!empty($realLink)) {
                $d->updateDirInfoById(['ct_cloud_download_link' => $realLink, 'ct_link_last_update' => time()], $fileId);
            }
        }

        if (empty($realLink)) {
            return $rsp->withStatus(403);
        }

//        return $rsp->withJson([
//            'data' => (preg_replace('/bytes=/', '', $req->getHeaderLine('range'))),
//            'origin' => $req->getHeaderLine('range')
//        ]);
        CtCloudHelper::downloadFile($realLink, $fileId, $fileInfo['name'], $fileInfo['file_length'], $req->getHeaderLine('range'));
        return $rsp->withStatus(200);
    }

}