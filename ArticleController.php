<?php

namespace AppBundle\Controller\Admin;

use andreskrey\Readability\Configuration;
use andreskrey\Readability\ParseException;
use andreskrey\Readability\Readability;
use AppBundle\Common\ArrayToolkit;
use AppBundle\Common\Paginator;
use Biz\Article\Service\ArticleService;
use Biz\File\Service\UploadFileService;
use Biz\System\Service\SettingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ArticleController extends BaseController
{
    public function indexAction(Request $request)
    {
        $conditions = $request->query->all();

        $categoryId = 0;

        if (!empty($conditions['categoryId'])) {
            $conditions['includeChildren'] = true;
            $categoryId = $conditions['categoryId'];
        }

        $conditions = $this->fillOrgCode($conditions);

        $paginator = new Paginator(
            $request,
            $this->getArticleService()->countArticles($conditions),
            20
        );

        $articles = $this->getArticleService()->searchArticles(
            $conditions,
            'normal',
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );
        $categoryIds = ArrayToolkit::column($articles, 'categoryId');
        $categories = $this->getCategoryService()->findCategoriesByIds($categoryIds);
        $categoryTree = $this->getCategoryService()->getCategoryTree();

        return $this->render('admin/article/index.html.twig', [
            'articles' => $articles,
            'categories' => $categories,
            'paginator' => $paginator,
            'categoryTree' => $categoryTree,
            'categoryId' => $categoryId,
        ]);
    }

    public function createAction(Request $request)
    {
        if ('POST' == $request->getMethod()) {
            $formData = $request->request->all();

            $article['tags'] = array_filter(explode(',', $formData['tags']));

            $article = $this->getArticleService()->createArticle($formData);

            $attachment = $request->request->get('attachment');
            $this->getUploadFileService()->createUseFiles($attachment['fileIds'], $article['id'], $attachment['targetType'], $attachment['type']);

            return $this->redirect($this->generateUrl('admin_article'));
        }

        $categoryTree = $this->getCategoryService()->getCategoryTree();

        return $this->render('admin/article/article-modal.html.twig', [
            'categoryTree' => $categoryTree,
            'category' => ['id' => 0, 'parentId' => 0],
        ]);
    }

    public function createFromUrlAction(Request $request)
    {
        //如果携带url参数，尝试识别url对应页面的资讯
        $url = $request->query->get('url');
        $isSafeDoman = $this->isSafeDoman($url);
        $article = ['title' => '', 'body' => ''];
        if ($url) {
            $url = urldecode($url);
            $readability = new Readability(new Configuration());
            $html = file_get_contents($url);
            try {
                $readability->parse($html);
                $article['title'] = $readability->getTitle();
                $article['body'] = $readability->getContent();
                $article['sourceUrl'] = $url;
                $article['thumb'] = $readability->getImage();
                $article['originalThumb'] = $readability->getImage();
            } catch (ParseException $e) {
                // echo sprintf('Error processing text: %s', $e->getMessage());
            }
        }

        if ('POST' == $request->getMethod()) {
            $formData = $request->request->all();

            $article['tags'] = array_filter(explode(',', $formData['tags']));

            $article = $this->getArticleService()->createArticle($formData);

            $attachment = $request->request->get('attachment');
            $this->getUploadFileService()->createUseFiles($attachment['fileIds'], $article['id'], $attachment['targetType'], $attachment['type']);

            return $this->redirect($this->generateUrl('admin_article'));
        }

        $categoryTree = $this->getCategoryService()->getCategoryTree();

        return $this->render('admin/article/article-from-url-modal.html.twig', [
            'url' => $url,
            'article' => $article,
            'categoryTree' => $categoryTree,
            'category' => ['id' => 0, 'parentId' => 0],
            'isSafeDoman' => $isSafeDoman,
        ]);
    }

    /**
     *  将网址的域名添加到白名单域
     */
    public function addDomainAction(Request $request)
    {
        $url = $request->query->get('url');
        $pu = parse_url($url);
        if (!empty($pu) && !empty($pu['host'])) {
            $host = $pu['host'];
            $currentUser = $this->getCurrentUser();
            if (!$currentUser->hasPermission('admin_setting_security')) {
                $this->setFlashMessage('danger', 'admin.article_setting.url.safe_domain_no_permission');
            } else {
                $security = $this->getSettingService()->get('security', []);
                if (!empty($security) && !empty($security['safe_iframe_domains'])) {
                    $security['safe_iframe_domains'][] = $host;
                } else {
                    $security = [
                        'safe_iframe_domains' => [$host],
                    ];
                }
                $this->getSettingService()->set('security', $security);
                $this->getLogService()->info('system', 'update_settings', '更新安全设置', $security);
                $this->setFlashMessage('success', 'site.save.success');
            }
        }

        return $this->redirect($this->generateUrl('admin_article_create_from_url', ['url' => $url]));
    }

    public function editAction(Request $request, $id)
    {
        $article = $this->getArticleService()->getArticle($id);

        if (empty($article)) {
            throw $this->createNotFoundException('文章已删除或者未发布！');
        }

        $tags = $this->getTagService()->findTagsByOwner([
            'ownerType' => 'article',
            'ownerId' => $id,
        ]);

        $tagNames = ArrayToolkit::column($tags, 'name');

        $categoryId = $article['categoryId'];
        $category = $this->getCategoryService()->getCategory($categoryId);

        $categoryTree = $this->getCategoryService()->getCategoryTree();

        if ('POST' == $request->getMethod()) {
            $formData = $request->request->all();
            $article = $this->getArticleService()->updateArticle($id, $formData);

            $attachment = $request->request->get('attachment');

            $this->getUploadFileService()->createUseFiles($attachment['fileIds'], $article['id'], $attachment['targetType'], $attachment['type']);

            return $this->redirect($this->generateUrl('admin_article'));
        }

        return $this->render('admin/article/article-modal.html.twig', [
            'article' => $article,
            'categoryTree' => $categoryTree,
            'category' => $category,
            'tagNames' => $tagNames,
        ]);
    }

    public function setArticlePropertyAction(Request $request, $id, $property)
    {
        $this->getArticleService()->setArticleProperty($id, $property);

        return $this->createJsonResponse(true);
    }

    public function cancelArticlePropertyAction(Request $request, $id, $property)
    {
        $this->getArticleService()->cancelArticleProperty($id, $property);

        return $this->createJsonResponse(true);
    }

    public function trashAction(Request $request, $id)
    {
        $this->getArticleService()->trashArticle($id);

        return $this->createJsonResponse(true);
    }

    public function thumbRemoveAction(Request $request, $id)
    {
        $this->getArticleService()->removeArticlethumb($id);

        return $this->createJsonResponse(true);
    }

    public function deleteAction(Request $request)
    {
        $ids = $request->request->get('ids', []);
        $id = $request->query->get('id', null);

        if ($id) {
            array_push($ids, $id);
        }

        $result = $this->getArticleService()->deleteArticlesByIds($ids);

        if ($result) {
            return $this->createJsonResponse(['status' => 'failed']);
        } else {
            return $this->createJsonResponse(['status' => 'success']);
        }
    }

    public function publishAction(Request $request, $id)
    {
        $this->getArticleService()->publishArticle($id);

        return $this->createJsonResponse(true);
    }

    public function unpublishAction(Request $request, $id)
    {
        $this->getArticleService()->unpublishArticle($id);

        return $this->createJsonResponse(true);
    }

    public function showUploadAction(Request $request)
    {
        return $this->render('admin/article/aticle-picture-modal.html.twig', [
            'pictureUrl' => '',
        ]);
    }

    public function pictureCropAction(Request $request)
    {
        if ('POST' == $request->getMethod()) {
            $options = $request->request->all();
            $files = $this->getArticleService()->changeIndexPicture($options['images']);

            foreach ($files as $key => $file) {
                $files[$key]['file']['url'] = $this->get('web.twig.extension')->getFilePath($file['file']['uri']);
            }

            return new JsonResponse($files);
        }

        $fileId = $request->getSession()->get('fileId');
        list($pictureUrl, $naturalSize, $scaledSize) = $this->getFileService()->getImgFileMetaInfo($fileId, 270, 270);

        return $this->render('admin/article/article-picture-crop-modal.html.twig', [
            'pictureUrl' => $pictureUrl,
            'naturalSize' => $naturalSize,
            'scaledSize' => $scaledSize,
        ]);
    }

    protected function isSafeDoman($url)
    {
        $security = $this->getSettingService()->get('security');
        if (!empty($security['safe_iframe_domains'])) {
            $safeDomains = $security['safe_iframe_domains'];
            foreach ($safeDomains as $safeDomain) {
                if (false !== strpos($url, $safeDomain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return ArticleService
     */
    protected function getArticleService()
    {
        return $this->createService('Article:ArticleService');
    }

    protected function getTagService()
    {
        return $this->createService('Taxonomy:TagService');
    }

    protected function getCategoryService()
    {
        return $this->createService('Article:CategoryService');
    }

    protected function getFileService()
    {
        return $this->createService('Content:FileService');
    }

    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->createService('System:SettingService');
    }

    /**
     * @return UploadFileService
     */
    protected function getUploadFileService()
    {
        return $this->createService('File:UploadFileService');
    }
}
