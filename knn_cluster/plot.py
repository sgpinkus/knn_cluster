#!/usr/bin/env python
import sys
import argparse
import json
import importlib
import os
from datetime import datetime
import numpy as np
import matplotlib.pyplot as plt
from knn_cluster.knn import KNNSearch


def main(argv):
  config, data, clusters, out_dir, q = load_config(argv)
  os.mkdir(out_dir)
  if(q):
    quivers(data, clusters, config, out_dir)
  else:
    dots(data, clusters, config, out_dir)
  try:
    os.system(f'convert -delay 50 -loop 0 {out_dir}/*.jpg {out_dir}/gif.gif')
  except Exception as e:
    print('Could not generate GIF via `convert`:', e)


def dots(data, clusters, config, out_dir):
  for k, labels in clusters.items():
    cmap = plt.get_cmap('tab20', max(labels)+1) # https://matplotlib.org/stable/users/explain/colors/colormaps.html#qualitative
    fig, ax = plt.subplots()
    ax.scatter(data[:, 0], data[:, 1], cmap=cmap, c=labels)
    plt.savefig(os.path.join(out_dir, f'{k}.jpg'))
    plt.clf()

def quivers(data, clusters, config, out_dir):
  knns = KNNSearch(data, config['distance']).knns(len(clusters))
  for k, labels in clusters.items():
    if k == 0: continue
    cmap = plt.get_cmap('tab20', max(labels)+1)
    fig, ax = plt.subplots()
    for i, l in enumerate(labels): # The index and class of a point.
      p = data[i]
      n = data.take(knns[i]['n'][:k], axis=0) - p
      p_tile = np.tile(p, k).reshape(k, 2)
      ax.scatter(data[:, 0], data[:, 1], cmap=cmap, c=labels, s=2)
      plt.quiver(p_tile[:,0], p_tile[:,1], n[:,0], n[:,1],
        scale=1,
        scale_units='xy',
        angles='xy',
        width=0.004, # Interpretation depends on scale_units (annoyingly) and `lw` does nothing.
        headlength=3,
        headaxislength=3,
        color=cmap(l)
      )
    plt.savefig(os.path.join(out_dir, f'{k}.jpg'))
    plt.clf()


def load_config(argv):
  parser = get_argparser()
  args = parser.parse_args(argv)
  with open(args.input_file, 'r', encoding='utf-8') as f: cluster_result_data = f.read().strip().split('\n')
  config, cluster_result_data = json.loads(cluster_result_data[0]), cluster_result_data[1:]
  data = load_module(config['data_file']).data
  clusters = { int(x): [int(z) for z in y.split(',')] for x, y in dict([v.split(':') for v in cluster_result_data]).items() }
  return config, data, clusters, args.out_dir or f'plots_{datetime.now().strftime('%F-%T')}', args.q


def load_module(module_file):
  def make_module_path(s):
    ''' Convert a possible filepath to a module-path. Does nothing it s is already a module-path '''
    return s.replace('.py', '').replace('/', '.').replace('..', '.').lstrip('.')
  config = importlib.import_module(make_module_path(module_file))
  return config


def get_argparser():
  parser = argparse.ArgumentParser(prog='knn_cluster plot')
  parser.add_argument('input_file', type=str, help='output file from knn_cluster.py representing a clustering')
  parser.add_argument('-d', '--out-dir', type=str, help='output directory')
  parser.add_argument('-q', help='draw quivers', action='store_true', default=False)
  return parser


if __name__ == '__main__':
  main(sys.argv[1:])