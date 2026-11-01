<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Form\Type;

use Pumukit\NewAdminBundle\Form\Type\Base\CustomLanguageType;
use Pumukit\NewAdminBundle\Form\Type\Other\TrackdurationType;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MultimediaObjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('opencastinvert', CheckboxType::class, [
                'required' => false,
                'mapped' => false,
                'label' => 'Invert',
                'help' => 'CAMERA-SCREEN',
            ])
            ->add('opencastlanguage', CustomLanguageType::class, [
                'required' => true,
                'mapped' => false,
                'label' => 'Language',
            ])
            ->add('durationinminutesandseconds', TrackdurationType::class, [
                'required' => false,
                'disabled' => true,
                'label' => 'Duration',
            ])
        ;

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $multimediaObject = $event->getData();
            $event->getForm()->get('opencastinvert')->setData((bool) $multimediaObject->getProperty('opencastinvert'));
            $event->getForm()->get('opencastlanguage')->setData($multimediaObject->getProperty('opencastlanguage'));
        });

        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $invert = (bool) $event->getForm()->get('opencastinvert')->getData();
            $multimediaObject = $event->getData();
            $multimediaObject->setProperty('opencastinvert', $invert);
            $multimediaObject->setProperty('paellalayout', $invert ? 'professor_slide' : 'slide_professor');
            $multimediaObject->setProperty('opencastlanguage', strtolower((string) $event->getForm()->get('opencastlanguage')->getData()));
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MultimediaObject::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'pumukit_opencast_multimedia_object';
    }
}
