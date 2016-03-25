<?php
namespace Topxia\WebBundle\Controller;

use Topxia\Common\Paginator;
use Topxia\Common\ArrayToolkit;
use Symfony\Component\HttpFoundation\Request;

class OpenCourseController extends BaseController
{
    public function exploreAction(Request $request, $category)
    {
        $conditions    = $request->query->all();
        $categoryArray = array();
        $levels        = array();

        $conditions['code'] = $category;

        if (!empty($conditions['code'])) {
            $categoryArray             = $this->getCategoryService()->getCategoryByCode($conditions['code']);
            $childrenIds               = $this->getCategoryService()->findCategoryChildrenIds($categoryArray['id']);
            $categoryIds               = array_merge($childrenIds, array($categoryArray['id']));
            $conditions['categoryIds'] = $categoryIds;
        }

        unset($conditions['code']);

        if (!isset($conditions['fliter'])) {
            $conditions['fliter'] = array(
                'type'           => 'all',
                'price'          => 'all',
                'currentLevelId' => 'all'
            );
        }

        $fliter = $conditions['fliter'];

        if ($fliter['price'] == 'free') {
            $coinSetting = $this->getSettingService()->get("coin");
            $coinEnable  = isset($coinSetting["coin_enabled"]) && $coinSetting["coin_enabled"] == 1;
            $priceType   = "RMB";

            if ($coinEnable && !empty($coinSetting) && array_key_exists("price_type", $coinSetting)) {
                $priceType = $coinSetting["price_type"];
            }

            if ($priceType == 'RMB') {
                $conditions['price'] = '0.00';
            } else {
                $conditions['coinPrice'] = '0.00';
            }
        }

        if ($fliter['type'] == 'live') {
            $conditions['type'] = 'live';
        }

        if ($this->isPluginInstalled('Vip')) {
            $levels = ArrayToolkit::index($this->getLevelService()->searchLevels(array('enabled' => 1), 0, 100), 'id');

            if ($fliter['currentLevelId'] != 'all') {
                $vipLevelIds               = ArrayToolkit::column($this->getLevelService()->findPrevEnabledLevels($fliter['currentLevelId']), 'id');
                $conditions['vipLevelIds'] = array_merge(array($fliter['currentLevelId']), $vipLevelIds);
            }
        }

        unset($conditions['fliter']);

        $courseSetting = $this->getSettingService()->get('course', array());

        if (!isset($courseSetting['explore_default_orderBy'])) {
            $courseSetting['explore_default_orderBy'] = 'latest';
        }

        $orderBy = $courseSetting['explore_default_orderBy'];
        $orderBy = empty($conditions['orderBy']) ? $orderBy : $conditions['orderBy'];
        unset($conditions['orderBy']);

        $conditions['parentId'] = 0;
        $conditions['status']   = 'published';
        $paginator              = new Paginator(
            $this->get('request'),
            $this->getCourseService()->searchCourseCount($conditions),
            20
        );

        if ($orderBy != 'recommendedSeq') {
            $courses = $this->getCourseService()->searchCourses(
                $conditions,
                $orderBy,
                $paginator->getOffsetCount(),
                $paginator->getPerPageCount()
            );
        }

        if ($orderBy == 'recommendedSeq') {
            $conditions['recommended'] = 1;
            $recommendCount            = $this->getCourseService()->searchCourseCount($conditions);
            $currentPage               = $request->query->get('page') ? $request->query->get('page') : 1;
            $recommendPage             = intval($recommendCount / 20);
            $recommendLeft             = $recommendCount % 20;

            if ($currentPage <= $recommendPage) {
                $courses = $this->getCourseService()->searchCourses(
                    $conditions,
                    $orderBy,
                    ($currentPage - 1) * 20,
                    20
                );
            } elseif (($recommendPage + 1) == $currentPage) {
                $courses = $this->getCourseService()->searchCourses(
                    $conditions,
                    $orderBy,
                    ($currentPage - 1) * 20,
                    20
                );
                $conditions['recommended'] = 0;
                $coursesTemp               = $this->getCourseService()->searchCourses(
                    $conditions,
                    'createdTime',
                    0,
                    20 - $recommendLeft
                );
                $courses = array_merge($courses, $coursesTemp);
            } else {
                $conditions['recommended'] = 0;
                $courses                   = $this->getCourseService()->searchCourses(
                    $conditions,
                    'createdTime',
                    (20 - $recommendLeft) + ($currentPage - $recommendPage - 2) * 20,
                    20
                );
            }
        }

        $group = $this->getCategoryService()->getGroupByCode('course');

        if (empty($group)) {
            $categories = array();
        } else {
            $categories = $this->getCategoryService()->getCategoryTree($group['id']);
        }

        if (!$categoryArray) {
            $categoryArrayDescription = array();
        } else {
            $categoryArrayDescription = $categoryArray['description'];
            $categoryArrayDescription = strip_tags($categoryArrayDescription, '');
            $categoryArrayDescription = preg_replace("/ /", "", $categoryArrayDescription);
            $categoryArrayDescription = substr($categoryArrayDescription, 0, 100);
        }

        if (!$categoryArray) {
            $categoryParent = '';
        } else {
            if (!$categoryArray['parentId']) {
                $categoryParent = '';
            } else {
                $categoryParent = $this->getCategoryService()->getCategory($categoryArray['parentId']);
            }
        }

        return $this->render('TopxiaWebBundle:Course:explore.html.twig', array(
            'courses'                  => $courses,
            'category'                 => $category,
            'fliter'                   => $fliter,
            'orderBy'                  => $orderBy,
            'paginator'                => $paginator,
            'categories'               => $categories,
            'consultDisplay'           => true,
            'path'                     => 'course_explore',
            'categoryArray'            => $categoryArray,
            'group'                    => $group,
            'categoryArrayDescription' => $categoryArrayDescription,
            'categoryParent'           => $categoryParent,
            'levels'                   => $levels
        ));
    }

    public function createAction(Request $request)
    {
        $course = $request->request->all();
        unset($course['buyable']);

        $course = $this->getOpenCourseService()->createCourse($course);

        return $this->redirect($this->generateUrl('course_manage', array('id' => $course['id'])));
    }

    public function showAction(Request $request, $courseId)
    {
        return $this->render("TopxiaWebBundle:OpenCourse:open-course-show.html.twig", array(
        ));
    }

    /**
     * Block Actions.
     */
    public function headerAction($course, $manage = false)
    {
        $user    = $this->getCurrentUser();
        $lessons = $this->getOpenCourseService()->findLessonsByCourseId($course['id']);

        return $this->render('TopxiaWebBundle:OpenCourse:open-course-header.html.twig', array(
            'course'  => $course,
            'lessons' => $lessons
        ));
    }

    public function teachersAction($courseId)
    {
        $course         = $this->getOpenCourseService()->getCourse($courseId);
        $teachersNoSort = $this->getUserService()->findUsersByIds($course['teacherIds']);

        $teachers = array();

        foreach ($course['teacherIds'] as $key => $teacherId) {
            $teachers[$teacherId] = $teachersNoSort[$teacherId];
        }

        return $this->render('TopxiaWebBundle:OpenCourse:open-course-teacher-block.html.twig', array(
            'course'   => $course,
            'teachers' => $teachers
        ));
    }

    protected function getOpenCourseService()
    {
        return $this->getServiceKernel()->createService('OpenCourse.OpenCourseService');
    }

    protected function getUserService()
    {
        return $this->getServiceKernel()->createService('User.UserService');
    }

    protected function getCategoryService()
    {
        return $this->getServiceKernel()->createService('Taxonomy.CategoryService');
    }

    protected function getTagService()
    {
        return $this->getServiceKernel()->createService('Taxonomy.TagService');
    }

    protected function getSettingService()
    {
        return $this->getServiceKernel()->createService('System.SettingService');
    }

    protected function getUploadFileService()
    {
        return $this->getServiceKernel()->createService('File.UploadFileService');
    }

    protected function getAppService()
    {
        return $this->getServiceKernel()->createService('CloudPlatform.AppService');
    }
}