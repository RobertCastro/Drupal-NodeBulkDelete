<?php

namespace Drupal\node_bulk_delete\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Datetime\Element\DateTime;
use Drupal\Core\Datetime\DrupalDateTime;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node_bulk_delete\Service\NodeBulkDeleteService;

/**
 * Formulario para la eliminación masiva de nodos.
 */
class NodeBulkDeleteForm extends FormBase {

  /**
   * @var \Drupal\node_bulk_delete\Service\NodeBulkDeleteService
   */
  protected $bulkDeleteService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('node_bulk_delete.service')
    );
  }

  /**
   * Constructor.
   */
  public function __construct(NodeBulkDeleteService $bulkDeleteService = NULL) {
    $this->bulkDeleteService = $bulkDeleteService;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'node_bulk_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Obtener tipos de contenido disponibles, excluyendo 'noticias'.
    $content_types = NodeType::loadMultiple();
    $options = [];
    foreach ($content_types as $type) {
      if ($type->id() !== 'noticias') {
        $options[$type->id()] = $type->label();
      }
    }

    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Tipo de contenido'),
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Seleccione -'),
      '#ajax' => [
        'callback' => '::updateNodeCount',
        'event' => 'change',
        'wrapper' => 'node-count-wrapper',
      ],
    ];

    $form['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Fecha de inicio'),
      '#required' => TRUE,
      '#default_value' => '2025-06-01',
      '#ajax' => [
        'callback' => '::updateNodeCount',
        'event' => 'change',
        'wrapper' => 'node-count-wrapper',
      ],
    ];
    $form['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Fecha de fin'),
      '#required' => TRUE,
      '#default_value' => '2025-08-28',
      '#ajax' => [
        'callback' => '::updateNodeCount',
        'event' => 'change',
        'wrapper' => 'node-count-wrapper',
      ],
    ];

    // Mostrar el número de nodos actuales y los que se eliminarán.
    $form['node_count'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'node-count-wrapper'],
    ];
    $counts = $this->getNodeCounts($form_state);
    $form['node_count']['current'] = [
      '#markup' => '<div><strong>' . $this->t('Nodos actuales:') . '</strong> ' . $counts['total'] . '</div>',
    ];
    $form['node_count']['to_delete'] = [
      '#markup' => '<div><strong>' . $this->t('Nodos a eliminar:') . '</strong> ' . $counts['to_delete'] . '</div>',
    ];

    // Botón de simulación (dry run) y de ejecución real.
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['dry_run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Simular eliminación (Dry Run)'),
      '#submit' => ['::submitDryRun'],
    ];
    $form['actions']['delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Eliminar nodos'),
      '#submit' => ['::submitDelete'],
      '#attributes' => ['class' => ['button--danger']],
    ];

    return $form;
  }

  /**
   * AJAX callback para actualizar el conteo de nodos.
   */
  public function updateNodeCount(array &$form, FormStateInterface $form_state) {
    return $form['node_count'];
  }

  /**
   * Convierte las fechas del formulario a timestamps UTC.
   * Si $is_end es TRUE, pone la hora en 23:59:59, si es FALSE en 00:00:00.
   */
  protected function convertFormDates($date_value, $is_end = FALSE) {
    if (empty($date_value)) {
      return NULL;
    }
    
    // Si es un string (formato YYYY-MM-DD)
    if (is_string($date_value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_value)) {
      $date_string = $date_value . ($is_end ? ' 23:59:59' : ' 00:00:00');
      return strtotime($date_string . ' UTC');
    }
    // Si es un DrupalDateTime
    if ($date_value instanceof DrupalDateTime) {
      if ($is_end) {
        $date_value->setTime(23, 59, 59);
      } else {
        $date_value->setTime(0, 0, 0);
      }
      return $date_value->getTimestamp();
    }
    // Si es un array con 'date'
    if (is_array($date_value) && isset($date_value['date'])) {
      $date_string = $date_value['date'] . ($is_end ? ' 23:59:59' : ' 00:00:00');
      return strtotime($date_string . ' UTC');
    }
    // Fallback
    return NULL;
  }

  /**
   * Obtiene el número de nodos actuales y los que se eliminarían.
   */
  protected function getNodeCounts(FormStateInterface $form_state) {
    $content_type = $form_state->getValue('content_type');
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    $start = $this->convertFormDates($start_date, FALSE);
    $end = $this->convertFormDates($end_date, TRUE);
    return $this->bulkDeleteService->getNodeCounts($content_type, $start, $end);
  }

  /**
   * Genera un archivo CSV con información de nodos.
   */
  protected function generateCsv($nodes_info, $filename_prefix) {
    $file_system = \Drupal::service('file_system');
    $public_path = \Drupal::service('file_system')->realpath('public://');
    $filename = $filename_prefix . '_' . date('Y-m-d_H-i-s') . '.csv';
    $file_path = $public_path . '/' . $filename;
    
    $handle = fopen($file_path, 'w');
    if (!$handle) {
      return FALSE;
    }
    
    // Escribir encabezados
    fputcsv($handle, ['Node ID', 'Path']);
    
    // Escribir datos
    foreach ($nodes_info as $node_info) {
      fputcsv($handle, [$node_info['nid'], $node_info['path']]);
    }
    
    fclose($handle);
    
    return 'public://' . $filename;
  }

  /**
   * Submit handler para simulación (dry run).
   */
  public function submitDryRun(array &$form, FormStateInterface $form_state) {
    $content_type = $form_state->getValue('content_type');
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    
    $start = $this->convertFormDates($start_date, FALSE);
    $end = $this->convertFormDates($end_date, TRUE);
    
    if (!$content_type || !$start || !$end) {
      $this->messenger()->addError($this->t('Debe seleccionar un tipo de contenido y un rango de fechas válido.'));
      return;
    }
    
    $nodes_info = $this->bulkDeleteService->getNodesInfo($content_type, $start, $end);
    $count = count($nodes_info);
    
    if ($count === 0) {
      $this->messenger()->addStatus($this->t('Simulación: No se encontraron nodos para eliminar.'));
      return;
    }
    
    $csv_file = $this->generateCsv($nodes_info, 'dry_run_nodes');
    
    if ($csv_file) {
      $this->messenger()->addStatus($this->t('Simulación: Se eliminarían @count nodos. Se generó archivo CSV: @file', [
        '@count' => $count,
        '@file' => $csv_file,
      ]));
    } else {
      $this->messenger()->addStatus($this->t('Simulación: Se eliminarían @count nodos. Error al generar archivo CSV.', ['@count' => $count]));
    }
  }

  /**
   * Submit handler para eliminación real.
   */
  public function submitDelete(array &$form, FormStateInterface $form_state) {
    $content_type = $form_state->getValue('content_type');
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    
    $start = $this->convertFormDates($start_date, FALSE);
    $end = $this->convertFormDates($end_date, TRUE);
    
    if (!$content_type || !$start || !$end) {
      $this->messenger()->addError($this->t('Debe seleccionar un tipo de contenido y un rango de fechas válido.'));
      return;
    }
    
    // Obtener información de nodos antes de eliminarlos
    $nodes_info = $this->bulkDeleteService->getNodesInfo($content_type, $start, $end);
    
    if (empty($nodes_info)) {
      $this->messenger()->addStatus($this->t('No se encontraron nodos para eliminar o el tipo de contenido no es permitido.'));
      return;
    }
    
    // Generar CSV antes de eliminar
    $csv_file = $this->generateCsv($nodes_info, 'deleted_nodes');
    
    // Eliminar nodos
    $count = $this->bulkDeleteService->deleteNodes($content_type, $start, $end);
    
    if ($csv_file) {
      $this->messenger()->addStatus($this->t('Se eliminaron @count nodos del tipo @type entre las fechas seleccionadas. Se generó archivo CSV: @file', [
        '@count' => $count,
        '@type' => $content_type,
        '@file' => $csv_file,
      ]));
    } else {
      $this->messenger()->addStatus($this->t('Se eliminaron @count nodos del tipo @type entre las fechas seleccionadas. Error al generar archivo CSV.', [
        '@count' => $count,
        '@type' => $content_type,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Este método es requerido por FormBase, pero no se usa porque se definen handlers personalizados.
  }
} 